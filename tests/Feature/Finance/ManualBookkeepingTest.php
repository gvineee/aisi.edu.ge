<?php

namespace Tests\Feature\Finance;

use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Academics\Models\Student;
use App\Domain\Finance\Actions\ManageFeeBilling;
use App\Domain\Finance\Actions\ReconcileFinance;
use App\Domain\Finance\Models\FeeCharge;
use App\Domain\Finance\Models\FeePlan;
use App\Domain\Finance\Models\FinanceSetting;
use App\Domain\Finance\Models\Invoice;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\Refund;
use App\Domain\Governance\Models\AuditEvent;
use App\Domain\Tenancy\Models\FeatureEntitlement;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Mail\InvoiceNoticeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Bookkeeping without a bank API: manual payments (allocation order,
 * credit, idempotency, duplicate statement line, reversal), manual credit
 * refunds, fee plans → contracts → idempotent invoice generation with
 * discounts, guardian balance view without online checkout, reminders,
 * audited exports, PDFs, MFA and tenant isolation.
 */
class ManualBookkeepingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function fixtures(): array
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;
        FeatureEntitlement::query()->updateOrCreate(['tenant_id' => $tenant->id, 'feature' => 'finance'], ['is_enabled' => true]);

        $year = $tenant->academicYears()->create(['name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-15', 'is_current' => true]);
        $class = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => 'TEST-VI']);

        $u = [];
        foreach (['accountant' => TenantMembership::ROLE_ACCOUNTANT, 'noMfa' => TenantMembership::ROLE_ACCOUNTANT, 'parent' => TenantMembership::ROLE_GUARDIAN, 'otherParent' => TenantMembership::ROLE_GUARDIAN, 'teacher' => TenantMembership::ROLE_TEACHER] as $key => $role) {
            $u[$key] = User::factory()->create();
            TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $u[$key]->id, 'role' => $role, 'is_active' => true]);
        }
        $u['accountant']->forceFill(['two_factor_confirmed_at' => now()])->save();

        $child = $tenant->students()->create(['school_class_id' => $class->id, 'first_name' => 'ტესტ', 'last_name' => 'მოსწავლე', 'is_active' => true]);
        $otherChild = $tenant->students()->create(['school_class_id' => $class->id, 'first_name' => 'მეორე', 'last_name' => 'მოსწავლე', 'is_active' => true]);

        foreach ([[$u['parent'], $child], [$u['otherParent'], $otherChild]] as [$guardian, $student]) {
            $link = new GuardianLink(['user_id' => $guardian->id, 'student_id' => $student->id, 'can_view_academic' => true, 'can_view_financial' => true, 'can_pickup' => false, 'can_receive_notifications' => true, 'is_active' => true]);
            $link->tenant_id = $tenant->id;
            $link->save();
        }

        return $u + compact('tenant', 'child', 'otherChild', 'year');
    }

    /**
     * @param  array<string, mixed>  $f
     */
    private function invoice(array $f, string $unit, int $dueInDays, ?Student $student = null): Invoice
    {
        $this->actingAs($f['accountant'])->post('/portal/finance/invoices', [
            'student_id' => ($student ?? $f['child'])->id,
            'due_on' => now('Asia/Tbilisi')->addDays(max(0, $dueInDays))->toDateString(),
            'items' => [['description' => 'საფასური', 'quantity' => 1, 'unit' => $unit]],
        ])->assertSessionHasNoErrors();

        $invoice = Invoice::query()->latest('id')->firstOrFail();

        if ($dueInDays < 0) {
            // Overdue fixtures: new invoices cannot be issued with a past due date.
            $invoice->forceFill(['due_on' => now('Asia/Tbilisi')->addDays($dueInDays)->toDateString()])->save();
        }

        return $invoice;
    }

    /**
     * @param  array<string, mixed>  $f
     * @param  array<string, mixed>  $overrides
     */
    private function pay(array $f, string $amount, array $overrides = []): TestResponse
    {
        return $this->actingAs($f['accountant'])->post('/portal/finance/payments', $overrides + [
            'student_id' => $f['child']->id,
            'amount' => $amount,
            'method' => 'cash',
            'received_on' => now('Asia/Tbilisi')->toDateString(),
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    public function test_manual_payment_settles_oldest_invoices_first_and_keeps_the_rest_as_credit(): void
    {
        $f = $this->fixtures();
        $later = $this->invoice($f, '100.00', 20);
        $sooner = $this->invoice($f, '50.00', 5);

        $this->pay($f, '120')->assertSessionHasNoErrors();

        $this->assertSame(Invoice::STATUS_PAID, $sooner->fresh()->status);
        $this->assertSame(Invoice::STATUS_PARTIALLY_PAID, $later->fresh()->status);
        $this->assertSame(7000, $later->fresh()->paid_minor);

        $this->pay($f, '40,50')->assertSessionHasNoErrors();
        $this->assertSame(Invoice::STATUS_PAID, $later->fresh()->status);
        $this->assertSame(1050, (int) Payment::query()->sum('unallocated_minor'));

        $this->assertTrue(app(ReconcileFinance::class)->handle($f['tenant']->id)['balanced']);
        $this->assertTrue(AuditEvent::query()->where('action', 'payment.manual_recorded')->exists());
    }

    public function test_payment_can_target_one_invoice(): void
    {
        $f = $this->fixtures();
        $first = $this->invoice($f, '50.00', 5);
        $target = $this->invoice($f, '80.00', 30);

        $this->pay($f, '80', ['invoice_id' => $target->id])->assertSessionHasNoErrors();

        $this->assertSame(Invoice::STATUS_PAID, $target->fresh()->status);
        $this->assertSame(Invoice::STATUS_ISSUED, $first->fresh()->status);

        // Another student's invoice cannot be targeted.
        $foreign = $this->invoice($f, '10.00', 5, $f['otherChild']);
        $this->pay($f, '10', ['invoice_id' => $foreign->id])->assertSessionHasErrors('invoice_id');
    }

    public function test_same_submission_is_recorded_once_and_a_statement_line_cannot_be_booked_twice(): void
    {
        $f = $this->fixtures();
        $key = (string) Str::uuid();

        $this->pay($f, '30', ['idempotency_key' => $key])->assertSessionHasNoErrors();
        $this->pay($f, '30', ['idempotency_key' => $key])->assertSessionHasNoErrors();
        $this->assertSame(1, Payment::query()->count());

        $this->pay($f, '25', ['method' => 'bank_transfer', 'reference' => 'TBC-7781'])->assertSessionHasNoErrors();
        $this->pay($f, '25', ['method' => 'bank_transfer', 'reference' => 'TBC-7781'])->assertSessionHasErrors('reference');
        $this->pay($f, '25', ['method' => 'bank_transfer'])->assertSessionHasErrors('reference');
        $this->pay($f, '25', ['received_on' => now('Asia/Tbilisi')->addDays(2)->toDateString()])->assertSessionHasErrors('received_on');
        $this->assertSame(2, Payment::query()->count());
    }

    public function test_reversal_reopens_invoices_and_keeps_books_balanced(): void
    {
        $f = $this->fixtures();
        $invoice = $this->invoice($f, '60.00', 5);
        $this->pay($f, '100')->assertSessionHasNoErrors();
        $payment = Payment::query()->firstOrFail();

        $this->actingAs($f['accountant'])->post("/portal/finance/payments/{$payment->id}/reverse", ['reason' => 'x'])->assertSessionHasErrors('reason');
        $this->actingAs($f['accountant'])->post("/portal/finance/payments/{$payment->id}/reverse", ['reason' => 'არასწორი მოსწავლე'])->assertSessionHasNoErrors();

        $invoice->refresh();
        $payment->refresh();
        $this->assertSame(Invoice::STATUS_ISSUED, $invoice->status);
        $this->assertSame(0, $invoice->paid_minor);
        $this->assertSame(10000, $payment->reversed_minor);
        $this->assertSame(0, $payment->unallocated_minor);
        $this->assertTrue(app(ReconcileFinance::class)->handle($f['tenant']->id)['balanced']);

        // The reopened invoice can be voided now, and paid again later.
        $this->pay($f, '60')->assertSessionHasNoErrors();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }

    public function test_credit_is_paid_back_by_hand(): void
    {
        $f = $this->fixtures();
        $this->pay($f, '50')->assertSessionHasNoErrors();
        $payment = Payment::query()->firstOrFail();

        $this->actingAs($f['accountant'])->post("/portal/finance/payments/{$payment->id}/manual-refund", ['amount' => '60', 'method' => 'cash', 'reason' => 'ზედმეტი თანხა'])->assertSessionHasErrors('amount');
        $this->actingAs($f['accountant'])->post("/portal/finance/payments/{$payment->id}/manual-refund", ['amount' => '20', 'method' => 'cash', 'reason' => 'ზედმეტი თანხა'])->assertSessionHasNoErrors();

        $this->assertSame(3000, $payment->fresh()->unallocated_minor);
        $this->assertSame('succeeded', Refund::query()->firstOrFail()->status);

        // A refunded payment can no longer be reversed.
        $this->actingAs($f['accountant'])->post("/portal/finance/payments/{$payment->id}/reverse", ['reason' => 'შეცდომა'])->assertSessionHasErrors('reason');
        $this->assertTrue(app(ReconcileFinance::class)->handle($f['tenant']->id)['balanced']);
    }

    public function test_fee_plan_generates_each_installment_once_with_discounts(): void
    {
        Mail::fake();
        $f = $this->fixtures();
        $today = now('Asia/Tbilisi');

        $this->actingAs($f['accountant'])->post('/portal/finance/fee-plans', [
            'name' => 'სატესტო გეგმა',
            'academic_year_id' => $f['year']->id,
            'installments' => [
                ['label' => 'I შენატანი', 'amount' => '1000', 'issue_on' => $today->copy()->subDay()->toDateString(), 'due_on' => $today->copy()->addDays(10)->toDateString()],
                ['label' => 'II შენატანი', 'amount' => '1000', 'issue_on' => $today->copy()->addMonth()->toDateString(), 'due_on' => $today->copy()->addMonths(2)->toDateString()],
            ],
        ])->assertSessionHasNoErrors();
        $plan = FeePlan::query()->firstOrFail();

        $this->actingAs($f['accountant'])->post("/portal/finance/fee-plans/{$plan->id}/contracts", ['student_id' => $f['child']->id, 'discount_percent' => 15])->assertSessionHasErrors('discount_reason');
        $this->actingAs($f['accountant'])->post("/portal/finance/fee-plans/{$plan->id}/contracts", ['student_id' => $f['child']->id, 'discount_percent' => 15, 'discount_reason' => 'და-ძმა'])->assertSessionHasNoErrors();
        $this->actingAs($f['accountant'])->post("/portal/finance/fee-plans/{$plan->id}/contracts", ['student_id' => $f['otherChild']->id, 'discount_percent' => 100, 'discount_reason' => 'სრული სტიპენდია'])->assertSessionHasNoErrors();

        $dry = app(ManageFeeBilling::class)->generate($f['tenant']->id, null, true, null);
        $this->assertSame(1, $dry['invoices']);
        $this->assertSame(1, $dry['waived']);
        $this->assertSame(0, Invoice::query()->count());

        $this->artisan('finance:generate-fee-invoices')->assertSuccessful();
        $this->artisan('finance:generate-fee-invoices')->assertSuccessful();
        $this->actingAs($f['accountant'])->post('/portal/finance/fee-plans/generate')->assertSessionHasNoErrors();

        $this->assertSame(1, Invoice::query()->count());
        $this->assertSame(85000, Invoice::query()->firstOrFail()->total_minor);
        $this->assertSame(2, FeeCharge::query()->count());
        Mail::assertQueued(InvoiceNoticeMail::class, fn (InvoiceNoticeMail $m) => $m->hasTo($f['parent']->email) && ! $m->reminder);
        Mail::assertNotQueued(InvoiceNoticeMail::class, fn (InvoiceNoticeMail $m) => $m->hasTo($f['otherParent']->email));

        // Issuing the second installment early, once.
        $second = $plan->installments()->get()->last();
        $this->actingAs($f['accountant'])->post('/portal/finance/fee-plans/generate', ['installment_id' => $second->id])->assertSessionHasNoErrors();
        $this->actingAs($f['accountant'])->post('/portal/finance/fee-plans/generate', ['installment_id' => $second->id])->assertSessionHasNoErrors();
        $this->assertSame(2, Invoice::query()->count());

        // An ended contract is not billed further.
        $this->assertSame(4, FeeCharge::query()->count());
    }

    public function test_guardian_sees_balance_and_instructions_but_cannot_pay_online_or_book(): void
    {
        $f = $this->fixtures();
        $invoice = $this->invoice($f, '75.00', -1);
        $this->pay($f, '25')->assertSessionHasNoErrors();
        $settings = new FinanceSetting(['recipient_name' => 'სატესტო სკოლა', 'bank_accounts' => [['bank' => 'ბანკი', 'iban' => 'GE00TB0000000000000000']]]);
        $settings->tenant_id = $f['tenant']->id;
        $settings->save();

        $this->actingAs($f['parent'])->get('/portal/finance')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('onlinePayments', false)
            ->where('paymentInfo.recipientName', 'სატესტო სკოლა')
            ->where('children.0.outstandingMinor', 5000)
            ->where('children.0.overdueMinor', 5000)
            ->where('children.0.payments.0.amountMinor', 2500)
            ->where('staff', null));

        $this->actingAs($f['parent'])->post("/portal/finance/invoices/{$invoice->id}/pay")->assertNotFound();
        $this->actingAs($f['parent'])->post('/portal/finance/payments', ['student_id' => $f['child']->id, 'amount' => '1', 'method' => 'cash', 'received_on' => now()->toDateString(), 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
        $this->actingAs($f['parent'])->get("/portal/finance/accounts/{$f['child']->id}")->assertForbidden();
        $this->actingAs($f['parent'])->get("/portal/finance/invoices/{$invoice->id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($f['otherParent'])->get("/portal/finance/invoices/{$invoice->id}/pdf")->assertForbidden();
        $receipt = Payment::query()->firstOrFail();
        $this->actingAs($f['otherParent'])->get("/portal/finance/payments/{$receipt->id}/receipt")->assertForbidden();
        $this->actingAs($f['teacher'])->get('/portal/finance/debtors')->assertForbidden();
    }

    public function test_staff_without_two_factor_is_refused(): void
    {
        $f = $this->fixtures();

        foreach (['/portal/finance/payments', '/portal/finance/debtors', '/portal/finance/fee-plans', '/portal/finance/settings', "/portal/finance/accounts/{$f['child']->id}", '/portal/finance/exports/debtors'] as $url) {
            $this->actingAs($f['noMfa'])->get($url)->assertForbidden();
        }
    }

    public function test_debtors_reminders_and_audited_exports(): void
    {
        Mail::fake();
        $f = $this->fixtures();
        $overdue = $this->invoice($f, '40.00', -3);
        $this->invoice($f, '10.00', 10, $f['otherChild']);

        $this->actingAs($f['accountant'])->get('/portal/finance/debtors?overdue=1')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('rows', 1)
            ->where('rows.0.overdueMinor', 4000)
            ->where('rows.0.daysOverdue', 3));

        $this->actingAs($f['accountant'])->post("/portal/finance/invoices/{$overdue->id}/remind")->assertSessionHasNoErrors();
        $this->actingAs($f['accountant'])->post("/portal/finance/invoices/{$overdue->id}/remind")->assertSessionHasErrors('invoice');
        Mail::assertQueued(InvoiceNoticeMail::class, fn (InvoiceNoticeMail $m) => $m->reminder && $m->hasTo($f['parent']->email));

        $csv = $this->actingAs($f['accountant'])->get('/portal/finance/exports/debtors')->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('ტესტ მოსწავლე', $csv);
        $this->actingAs($f['accountant'])->get('/portal/finance/exports/invoices')->assertOk();
        $this->actingAs($f['accountant'])->get('/portal/finance/exports/payments')->assertOk();
        $this->assertSame(3, AuditEvent::query()->where('action', 'finance.exported')->count());
    }

    public function test_other_tenant_records_are_not_reachable(): void
    {
        $f = $this->fixtures();
        $other = Tenant::create(['slug' => 'other-fin', 'name' => 'Other', 'locale' => 'ka', 'timezone' => 'Asia/Tbilisi', 'is_active' => true]);
        $otherYear = $other->academicYears()->create(['name' => 'X', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-15', 'is_current' => true]);
        $otherClass = $other->schoolClasses()->create(['academic_year_id' => $otherYear->id, 'name' => 'X']);
        $foreign = $other->students()->create(['school_class_id' => $otherClass->id, 'first_name' => 'უცხო', 'last_name' => 'მოსწავლე', 'is_active' => true]);

        $this->actingAs($f['accountant'])->get("/portal/finance/accounts/{$foreign->id}")->assertNotFound();
        $this->pay($f, '10', ['student_id' => $foreign->id])->assertNotFound();
        $this->actingAs($f['accountant'])->get('/portal/finance/debtors')->assertOk()->assertInertia(fn (Assert $page) => $page->has('rows', 0));
    }

    public function test_settings_validate_iban_and_accountant_lands_on_finance(): void
    {
        $f = $this->fixtures();

        $this->actingAs($f['accountant'])->put('/portal/finance/settings', ['bank_accounts' => [['bank' => 'ბანკი', 'iban' => 'not-an-iban']]])->assertSessionHasErrors('bank_accounts.0.iban');
        $this->actingAs($f['accountant'])->put('/portal/finance/settings', ['recipient_name' => 'სკოლა', 'bank_accounts' => [['bank' => 'ბანკი', 'iban' => 'ge00tb0000000000000000']]])->assertSessionHasNoErrors();
        $this->assertSame('GE00TB0000000000000000', FinanceSetting::forTenant($f['tenant']->id)->bank_accounts[0]['iban']);

        $this->actingAs($f['accountant'])->get('/dashboard')->assertRedirect('/portal/finance');
    }

    public function test_due_date_in_the_past_installment_is_billed_due_today(): void
    {
        $f = $this->fixtures();
        $today = Carbon::now('Asia/Tbilisi');

        $plan = app(ManageFeeBilling::class)->createPlan($f['tenant']->id, 'ძველი', null, [
            ['label' => 'I', 'amount_minor' => 1000, 'issue_on' => $today->copy()->subMonth()->toDateString(), 'due_on' => $today->copy()->subWeek()->toDateString()],
        ], $f['accountant']);
        app(ManageFeeBilling::class)->addContract($plan, $f['child'], 0, null, 'N-1', null, null, $f['accountant']);

        $result = app(ManageFeeBilling::class)->generate($f['tenant']->id, null, false, null);

        $this->assertSame(1, $result['invoices']);
        $this->assertSame($today->toDateString(), Invoice::query()->firstOrFail()->due_on->toDateString());
    }
}
