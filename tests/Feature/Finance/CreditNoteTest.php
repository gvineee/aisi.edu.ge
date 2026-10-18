<?php

namespace Tests\Feature\Finance;

use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Finance\Actions\ReconcileFinance;
use App\Domain\Finance\Models\CreditNote;
use App\Domain\Finance\Models\Invoice;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Domain\Governance\Models\AuditEvent;
use App\Domain\Tenancy\Models\FeatureEntitlement;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Credit notes: lowering an unpaid, partly paid or fully paid invoice,
 * releasing overpaid money to account credit (newest allocation first),
 * own number series, idempotency, limits, void interplay, reconciliation,
 * guardian visibility, MFA, reauth and tenant isolation.
 */
class CreditNoteTest extends TestCase
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
        $class = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => 'TEST-VII']);

        $u = [];
        foreach (['accountant' => TenantMembership::ROLE_ACCOUNTANT, 'noMfa' => TenantMembership::ROLE_ACCOUNTANT, 'parent' => TenantMembership::ROLE_GUARDIAN, 'otherParent' => TenantMembership::ROLE_GUARDIAN] as $key => $role) {
            $u[$key] = User::factory()->create();
            TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $u[$key]->id, 'role' => $role, 'is_active' => true]);
        }
        $u['accountant']->forceFill(['two_factor_confirmed_at' => now()])->save();

        $child = $tenant->students()->create(['school_class_id' => $class->id, 'first_name' => 'ტესტ', 'last_name' => 'მოსწავლე', 'is_active' => true]);
        $link = new GuardianLink(['user_id' => $u['parent']->id, 'student_id' => $child->id, 'can_view_academic' => true, 'can_view_financial' => true, 'can_pickup' => false, 'can_receive_notifications' => true, 'is_active' => true]);
        $link->tenant_id = $tenant->id;
        $link->save();

        return $u + compact('tenant', 'child');
    }

    /**
     * @param  array<string, mixed>  $f
     */
    private function invoice(array $f, string $unit): Invoice
    {
        $this->actingAs($f['accountant'])->post('/portal/finance/invoices', [
            'student_id' => $f['child']->id,
            'due_on' => now('Asia/Tbilisi')->addDays(10)->toDateString(),
            'items' => [['description' => 'საფასური', 'quantity' => 1, 'unit' => $unit]],
        ])->assertSessionHasNoErrors();

        return Invoice::query()->latest('id')->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $f
     */
    private function pay(array $f, string $amount, Invoice $invoice): void
    {
        $this->actingAs($f['accountant'])->post('/portal/finance/payments', [
            'student_id' => $f['child']->id,
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'method' => 'cash',
            'received_on' => now('Asia/Tbilisi')->toDateString(),
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();
    }

    /**
     * @param  array<string, mixed>  $f
     */
    private function credit(array $f, Invoice $invoice, string $amount, ?string $key = null, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $f['accountant'])->post("/portal/finance/invoices/{$invoice->id}/credit-notes", [
            'amount' => $amount,
            'reason' => 'ფასდაკლება შეთანხმებით',
            'idempotency_key' => $key ?? (string) Str::uuid(),
        ]);
    }

    private function balanced(int $tenantId): bool
    {
        return app(ReconcileFinance::class)->handle($tenantId)['balanced'];
    }

    public function test_credit_note_lowers_an_unpaid_invoice_and_can_close_it(): void
    {
        $f = $this->fixtures();
        $invoice = $this->invoice($f, '100.00');

        $this->credit($f, $invoice, '30')->assertSessionHasNoErrors();
        $invoice->refresh();
        $this->assertSame(3000, $invoice->credited_minor);
        $this->assertSame(7000, $invoice->outstandingMinor());
        $this->assertSame(Invoice::STATUS_ISSUED, $invoice->status);

        $year = now('Asia/Tbilisi')->year;
        $this->assertSame(sprintf('CN-%d-000001', $year), CreditNote::query()->firstOrFail()->number);

        $this->credit($f, $invoice, '70')->assertSessionHasNoErrors();
        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_CREDITED, $invoice->status);
        $this->assertSame(0, $invoice->outstandingMinor());
        $this->assertFalse($invoice->isOpen());
        $this->assertSame(sprintf('CN-%d-000002', $year), CreditNote::query()->latest('id')->firstOrFail()->number);

        // Nothing left to credit.
        $this->credit($f, $invoice, '1')->assertSessionHasErrors('amount');
        $this->assertTrue($this->balanced($f['tenant']->id));
        $this->assertSame(2, AuditEvent::query()->where('action', 'credit_note.issued')->count());
    }

    public function test_overpaid_part_goes_back_to_account_credit_newest_allocation_first(): void
    {
        $f = $this->fixtures();
        $invoice = $this->invoice($f, '100.00');
        $this->pay($f, '60', $invoice);
        $this->pay($f, '40', $invoice);
        [$first, $second] = Payment::query()->orderBy('id')->get()->all();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);

        $this->credit($f, $invoice, '50')->assertSessionHasNoErrors();

        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertSame(5000, $invoice->paid_minor);
        $this->assertSame(0, $invoice->outstandingMinor());
        // The newer 40 is released fully, then 10 of the older 60.
        $this->assertSame(4000, $second->fresh()->unallocated_minor);
        $this->assertSame(1000, $first->fresh()->unallocated_minor);
        $this->assertSame(5000, CreditNote::query()->firstOrFail()->released_minor);
        $this->assertSame(5000, (int) PaymentAllocation::query()->active()->sum('amount_minor'));
        // Both originals are stamped; one replacement carries the kept 50.
        $this->assertSame(2, PaymentAllocation::query()->whereNotNull('reversed_at')->count());
        $this->assertSame(1, PaymentAllocation::query()->active()->count());
        $this->assertTrue($this->balanced($f['tenant']->id));

        // The released credit can be paid back by hand.
        $this->actingAs($f['accountant'])->post("/portal/finance/payments/{$second->id}/manual-refund", ['amount' => '40', 'method' => 'bank_transfer', 'reason' => 'ფასდაკლების დაბრუნება'])->assertSessionHasNoErrors();
        $this->assertTrue($this->balanced($f['tenant']->id));
    }

    public function test_crediting_a_partly_paid_invoice_releases_only_what_exceeds_the_new_total(): void
    {
        $f = $this->fixtures();
        $invoice = $this->invoice($f, '100.00');
        $this->pay($f, '70', $invoice);

        $this->credit($f, $invoice, '20')->assertSessionHasNoErrors();
        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_PARTIALLY_PAID, $invoice->status);
        $this->assertSame(1000, $invoice->outstandingMinor());
        $this->assertSame(0, CreditNote::query()->firstOrFail()->released_minor);

        $this->credit($f, $invoice, '30')->assertSessionHasNoErrors();
        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertSame(5000, $invoice->paid_minor);
        $this->assertSame(2000, Payment::query()->firstOrFail()->unallocated_minor);
        $this->assertTrue($this->balanced($f['tenant']->id));
    }

    public function test_same_submission_issues_one_credit_note_and_limits_hold(): void
    {
        $f = $this->fixtures();
        $invoice = $this->invoice($f, '50.00');
        $key = (string) Str::uuid();

        $this->credit($f, $invoice, '10', $key)->assertSessionHasNoErrors();
        $this->credit($f, $invoice, '10', $key)->assertSessionHasNoErrors();
        $this->assertSame(1, CreditNote::query()->count());
        $this->assertSame(1000, $invoice->fresh()->credited_minor);

        $this->credit($f, $invoice, '40.01')->assertSessionHasErrors('amount');
        $this->credit($f, $invoice, '0')->assertSessionHasErrors('amount');
        $this->actingAs($f['accountant'])->post("/portal/finance/invoices/{$invoice->id}/credit-notes", [
            'amount' => '5', 'reason' => 'x', 'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors('reason');
        $this->assertSame(1, CreditNote::query()->count());
    }

    public function test_void_and_credit_notes_exclude_each_other(): void
    {
        $f = $this->fixtures();
        $credited = $this->invoice($f, '50.00');
        $this->credit($f, $credited, '10')->assertSessionHasNoErrors();
        $this->actingAs($f['accountant'])->post("/portal/finance/invoices/{$credited->id}/void", ['reason' => 'შეცდომით გამოწერილი'])->assertSessionHasErrors('reason');
        $this->assertNotSame(Invoice::STATUS_VOID, $credited->fresh()->status);

        $voided = $this->invoice($f, '20.00');
        $this->actingAs($f['accountant'])->post("/portal/finance/invoices/{$voided->id}/void", ['reason' => 'შეცდომით გამოწერილი'])->assertSessionHasNoErrors();
        $this->credit($f, $voided, '5')->assertSessionHasErrors('amount');
    }

    public function test_guardian_sees_credit_notes_and_pdf_but_cannot_issue_them(): void
    {
        $f = $this->fixtures();
        $invoice = $this->invoice($f, '80.00');
        $this->credit($f, $invoice, '15')->assertSessionHasNoErrors();
        $creditNote = CreditNote::query()->firstOrFail();

        $this->actingAs($f['parent'])->get("/portal/finance/invoices/{$invoice->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('invoice.creditedMinor', 1500)
            ->where('invoice.outstandingMinor', 6500)
            ->where('invoice.creditNotes.0.number', $creditNote->number));
        $this->actingAs($f['parent'])->get("/portal/finance/credit-notes/{$creditNote->id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($f['accountant'])->get("/portal/finance/invoices/{$invoice->id}/pdf")->assertOk();

        $this->actingAs($f['otherParent'])->get("/portal/finance/credit-notes/{$creditNote->id}/pdf")->assertForbidden();
        $this->credit($f, $invoice, '5', null, $f['parent'])->assertForbidden();
        $this->credit($f, $invoice, '5', null, $f['noMfa'])->assertForbidden();
        $this->assertSame(1, CreditNote::query()->count());
    }

    public function test_issuing_needs_a_recent_password_confirmation(): void
    {
        $f = $this->fixtures();
        $invoice = $this->invoice($f, '30.00');
        config(['security.privileged_reauth' => true]);

        $this->credit($f, $invoice, '5')->assertRedirect(route('password.confirm'));
        $this->assertSame(0, CreditNote::query()->count());
    }

    public function test_another_tenant_cannot_reach_credit_notes(): void
    {
        $f = $this->fixtures();
        $invoice = $this->invoice($f, '30.00');
        $this->credit($f, $invoice, '5')->assertSessionHasNoErrors();
        $creditNote = CreditNote::query()->firstOrFail();

        $other = Tenant::create(['slug' => 'other-cn', 'name' => 'Other', 'locale' => 'ka', 'timezone' => 'Asia/Tbilisi', 'is_active' => true]);
        TenantDomain::create(['tenant_id' => $other->id, 'domain' => 'other.test', 'is_primary' => true]);
        FeatureEntitlement::query()->updateOrCreate(['tenant_id' => $other->id, 'feature' => 'finance'], ['is_enabled' => true]);
        $otherAccountant = User::factory()->create(['two_factor_confirmed_at' => now()]);
        TenantMembership::create(['tenant_id' => $other->id, 'user_id' => $otherAccountant->id, 'role' => TenantMembership::ROLE_ACCOUNTANT, 'is_active' => true]);

        $this->actingAs($otherAccountant)->get("http://other.test/portal/finance/credit-notes/{$creditNote->id}/pdf")->assertNotFound();
        $this->actingAs($otherAccountant)->post("http://other.test/portal/finance/invoices/{$invoice->id}/credit-notes", [
            'amount' => '5', 'reason' => 'უცხო სკოლა', 'idempotency_key' => (string) Str::uuid(),
        ])->assertNotFound();
        $this->assertSame(1, CreditNote::query()->count());
    }
}
