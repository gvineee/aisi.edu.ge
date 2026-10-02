<?php

namespace Tests\Feature\Finance;

use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Finance\Actions\ReconcileFinance;
use App\Domain\Finance\Models\Invoice;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentIntent;
use App\Domain\Finance\Models\ProviderWebhookEvent;
use App\Domain\Finance\Models\Refund;
use App\Domain\Finance\Providers\SandboxPaymentProvider;
use App\Domain\Tenancy\Models\FeatureEntitlement;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Http\Controllers\Portal\FinanceController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * CLAUDE.md #6 and CLAUDE-PLATFORM-MODULES §12/§17: integer money,
 * per-tenant/year invoice numbers, the return page proves nothing, signed
 * + idempotent webhooks, amount/currency mismatch, out-of-order events,
 * partial and over-payment, allocation limits, refunds, reconciliation,
 * MFA for finance staff, guardian financial permission, and tenant mapping
 * from the trusted intent.
 */
class FinanceSandboxTest extends TestCase
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
        $class = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => 'VI']);

        $u = [];
        foreach (['accountant' => TenantMembership::ROLE_ACCOUNTANT, 'noMfaAccountant' => TenantMembership::ROLE_ACCOUNTANT, 'payer' => TenantMembership::ROLE_GUARDIAN, 'nonFinancialGuardian' => TenantMembership::ROLE_GUARDIAN, 'otherGuardian' => TenantMembership::ROLE_GUARDIAN] as $key => $role) {
            $u[$key] = User::factory()->create();
            TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $u[$key]->id, 'role' => $role, 'is_active' => true]);
        }
        $u['accountant']->forceFill(['two_factor_confirmed_at' => now()])->save();

        $child = $tenant->students()->create(['school_class_id' => $class->id, 'first_name' => 'ნიკა', 'last_name' => 'დ.', 'is_active' => true]);
        $otherChild = $tenant->students()->create(['school_class_id' => $class->id, 'first_name' => 'ანა', 'last_name' => 'ბ.', 'is_active' => true]);

        $this->link($tenant, $u['payer'], $child->id, true);
        $this->link($tenant, $u['nonFinancialGuardian'], $child->id, false);
        $this->link($tenant, $u['otherGuardian'], $otherChild->id, true);

        return $u + compact('tenant', 'child', 'otherChild');
    }

    private function link(Tenant $tenant, User $guardian, int $studentId, bool $financial): void
    {
        $link = new GuardianLink(['user_id' => $guardian->id, 'student_id' => $studentId, 'can_view_academic' => true, 'can_view_financial' => $financial, 'can_pickup' => false, 'can_receive_notifications' => true, 'is_active' => true]);
        $link->tenant_id = $tenant->id;
        $link->save();
    }

    /**
     * @param  array<string, mixed>  $f
     */
    private function issue(array $f, string $unit = '150.00', ?int $studentId = null): Invoice
    {
        $this->actingAs($f['accountant'])->post('/portal/finance/invoices', [
            'student_id' => $studentId ?? $f['child']->id,
            'due_on' => now('Asia/Tbilisi')->addWeek()->toDateString(),
            'items' => [['description' => 'სწავლის საფასური — ოქტომბერი', 'quantity' => 1, 'unit' => $unit]],
        ])->assertSessionHasNoErrors();

        return Invoice::query()->latest('id')->firstOrFail();
    }

    private function startIntent(User $payer, Invoice $invoice): PaymentIntent
    {
        $this->actingAs($payer)->post("/portal/finance/invoices/{$invoice->id}/pay", [], ['X-Inertia' => 'true'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location');

        return PaymentIntent::query()->latest('id')->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function webhook(string $type, array $fields, ?string $signatureOverride = null, string $host = 'localhost'): TestResponse
    {
        $event = app(SandboxPaymentProvider::class)->makeEvent($type, $fields);

        return $this->call('POST', "http://{$host}/webhooks/payments/sandbox", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SANDBOX_SIGNATURE' => $signatureOverride ?? $event['signature'],
            'HTTP_HOST' => $host,
        ], $event['body']);
    }

    /**
     * @return array<string, mixed>
     */
    private function successFor(PaymentIntent $intent, ?int $amount = null, ?string $currency = null): array
    {
        return ['reference' => $intent->provider_reference, 'payment_id' => 'sbxp_'.bin2hex(random_bytes(6)), 'amount_minor' => $amount ?? $intent->amount_minor, 'currency' => $currency ?? $intent->currency];
    }

    public function test_money_and_invoice_numbering(): void
    {
        $f = $this->fixtures();

        $this->assertSame(4550, FinanceController::toMinor('45.5'));
        $this->assertSame(4505, FinanceController::toMinor('45.05'));
        $this->assertSame(100, FinanceController::toMinor('1'));

        $first = $this->issue($f, '150.00');
        $second = $this->issue($f, '0.99');
        $year = now('Asia/Tbilisi')->format('Y');

        $this->assertSame(15000, $first->total_minor);
        $this->assertSame(99, $second->total_minor);
        $this->assertSame("{$year}-000001", $first->number);
        $this->assertSame("{$year}-000002", $second->number);
        $this->assertSame('GEL', $first->currency);

        $this->actingAs($f['accountant'])->post('/portal/finance/invoices', [
            'student_id' => $f['child']->id, 'due_on' => now()->addWeek()->toDateString(),
            'items' => [['description' => 'x', 'quantity' => 1, 'unit' => '1.234']],
        ])->assertSessionHasErrors('items.0.unit');
    }

    public function test_finance_staff_need_mfa_and_guardians_need_the_financial_permission(): void
    {
        $f = $this->fixtures();
        $invoice = $this->issue($f);

        $this->actingAs($f['noMfaAccountant'])->get('/portal/finance')->assertInertia(fn (Assert $page) => $page->where('needsMfa', true)->where('staff', null));
        $this->actingAs($f['noMfaAccountant'])->post('/portal/finance/invoices', ['student_id' => $f['child']->id])->assertForbidden();

        $this->actingAs($f['payer'])->get("/portal/finance/invoices/{$invoice->id}")->assertOk();
        $this->actingAs($f['nonFinancialGuardian'])->get("/portal/finance/invoices/{$invoice->id}")->assertForbidden();
        $this->actingAs($f['nonFinancialGuardian'])->get('/portal/finance')->assertForbidden();
        $this->actingAs($f['otherGuardian'])->get("/portal/finance/invoices/{$invoice->id}")->assertForbidden();
        $this->actingAs($f['otherGuardian'])->post("/portal/finance/invoices/{$invoice->id}/pay")->assertForbidden();

        $this->actingAs($f['payer'])->get('/portal/finance')
            ->assertInertia(fn (Assert $page) => $page->where('children.0.invoices.0.id', $invoice->id)->has('children', 1));

        FeatureEntitlement::query()->where('feature', 'finance')->update(['is_enabled' => false]);
        $this->actingAs($f['payer'])->get('/portal/finance')->assertNotFound();
    }

    public function test_return_page_does_not_prove_payment_and_sandbox_success_pays_invoice(): void
    {
        $f = $this->fixtures();
        $invoice = $this->issue($f);
        $intent = $this->startIntent($f['payer'], $invoice);

        $this->assertSame(15000, $intent->amount_minor);
        $this->actingAs($f['payer'])->get("/portal/finance/return/{$intent->id}")->assertInertia(fn (Assert $page) => $page->where('intent.status', 'created'));
        $this->assertSame('issued', $invoice->fresh()->status);

        $this->actingAs($f['otherGuardian'])->post("/portal/finance/sandbox/{$intent->id}/simulate", ['outcome' => 'success'])->assertForbidden();
        $this->actingAs($f['payer'])->post("/portal/finance/sandbox/{$intent->id}/simulate", ['outcome' => 'success'])->assertRedirect(route('finance.return', $intent));

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(15000, $invoice->fresh()->paid_minor);
        $this->assertSame(1, Payment::query()->count());
    }

    public function test_webhook_signature_idempotency_mismatch_and_out_of_order(): void
    {
        $f = $this->fixtures();
        $invoice = $this->issue($f);
        $intent = $this->startIntent($f['payer'], $invoice);

        // Bad signature: refused, recorded, nothing paid, the event id is not consumed.
        $this->webhook('payment.succeeded', $this->successFor($intent), 'forged')->assertStatus(400);
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame('rejected_signature', ProviderWebhookEvent::query()->value('outcome'));

        // Mismatched amount / currency: rejected, nothing paid.
        $this->webhook('payment.succeeded', $this->successFor($intent, 14999))->assertOk()->assertJson(['outcome' => 'rejected_mismatch']);
        $this->webhook('payment.succeeded', $this->successFor($intent, null, 'USD'))->assertOk()->assertJson(['outcome' => 'rejected_mismatch']);
        $this->assertSame(0, Payment::query()->count());

        // A failure notice arriving before the success (out-of-order) does not block it.
        $this->webhook('payment.failed', ['reference' => $intent->provider_reference])->assertJson(['outcome' => 'processed']);
        $this->assertSame('failed', $intent->fresh()->status);

        // Valid success, then the exact same event delivered again.
        $event = app(SandboxPaymentProvider::class)->makeEvent('payment.succeeded', $this->successFor($intent));
        $send = fn () => $this->call('POST', 'http://localhost/webhooks/payments/sandbox', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SANDBOX_SIGNATURE' => $event['signature']], $event['body']);
        $send()->assertJson(['outcome' => 'processed']);
        $send()->assertJson(['outcome' => 'duplicate_ignored']);
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame('paid', $invoice->fresh()->status);

        // A late failure after success is ignored.
        $this->webhook('payment.failed', ['reference' => $intent->provider_reference])->assertJson(['outcome' => 'ignored_out_of_order']);
        $this->assertSame('succeeded', $intent->fresh()->status);

        // Unknown reference: unmatched, no tenant attached.
        $this->webhook('payment.succeeded', ['reference' => 'sbx_unknown', 'payment_id' => 'x1', 'amount_minor' => 100, 'currency' => 'GEL'])->assertJson(['outcome' => 'unmatched']);
        $this->assertNull(ProviderWebhookEvent::query()->where('outcome', 'unmatched')->value('tenant_id'));
    }

    public function test_overpayment_becomes_credit_partial_allocation_refund_and_reconciliation(): void
    {
        $f = $this->fixtures();
        $invoiceA = $this->issue($f, '100.00');

        // The payer opened checkout twice and both succeeded: the second payment is pure credit.
        $first = $this->startIntent($f['payer'], $invoiceA);
        $second = $this->startIntent($f['payer'], $invoiceA);
        $this->webhook('payment.succeeded', $this->successFor($first))->assertJson(['outcome' => 'processed']);
        $this->webhook('payment.succeeded', $this->successFor($second))->assertJson(['outcome' => 'processed']);

        $this->assertSame(10000, $invoiceA->fresh()->paid_minor);
        $creditPayment = Payment::query()->where('payment_intent_id', $second->id)->firstOrFail();
        $this->assertSame(10000, $creditPayment->unallocated_minor);

        $invoiceB = $this->issue($f, '60.00');

        // Allocation limits: not more than the invoice's outstanding nor the credit.
        $this->actingAs($f['accountant'])->post("/portal/finance/payments/{$creditPayment->id}/allocate", ['invoice_id' => $invoiceB->id, 'amount' => '70.00'])->assertSessionHasErrors('amount');
        $this->actingAs($f['accountant'])->post("/portal/finance/payments/{$creditPayment->id}/allocate", ['invoice_id' => $invoiceB->id, 'amount' => '25.00'])->assertSessionHasNoErrors();
        $this->assertSame('partially_paid', $invoiceB->fresh()->status);
        $this->assertSame(2500, $invoiceB->fresh()->paid_minor);

        // Another family's invoice cannot receive this credit.
        $foreign = $this->issue($f, '10.00', $f['otherChild']->id);
        $this->actingAs($f['accountant'])->post("/portal/finance/payments/{$creditPayment->id}/allocate", ['invoice_id' => $foreign->id, 'amount' => '5.00'])->assertSessionHasErrors('invoice_id');

        // Refund the remaining credit (75.00): limited to credit, then confirmed by signed webhook.
        $this->actingAs($f['accountant'])->post("/portal/finance/payments/{$creditPayment->id}/refund", ['amount' => '80.00', 'reason' => 'ზედმეტი გადახდა'])->assertSessionHasErrors('amount');
        $this->actingAs($f['accountant'])->post("/portal/finance/payments/{$creditPayment->id}/refund", ['amount' => '75.00', 'reason' => 'ზედმეტი გადახდა'])->assertSessionHasNoErrors();
        $refund = Refund::query()->firstOrFail();
        $this->assertSame('requested', $refund->status);
        $this->webhook('refund.succeeded', ['refund_id' => $refund->provider_refund_id, 'amount_minor' => 7500, 'currency' => 'GEL'])->assertJson(['outcome' => 'processed']);
        $this->assertSame('succeeded', $refund->fresh()->status);

        // Paid invoices cannot be voided; an untouched one can, with a reason.
        $this->actingAs($f['accountant'])->post("/portal/finance/invoices/{$invoiceA->id}/void", ['reason' => 'შეცდომით'])->assertSessionHasErrors('reason');
        $this->actingAs($f['accountant'])->post("/portal/finance/invoices/{$foreign->id}/void", ['reason' => 'შეცდომით გამოწერილი'])->assertSessionHasNoErrors();
        $this->assertSame('void', $foreign->fresh()->status);

        $report = app(ReconcileFinance::class)->handle($f['tenant']->id);
        $this->assertTrue($report['balanced']);
        $this->assertSame(20000, $report['totals']['paymentsMinor']);
        $this->assertSame(12500, $report['totals']['allocatedMinor']);
        $this->assertSame(7500, $report['totals']['refundedMinor']);
        $this->assertSame(0, $report['totals']['creditMinor']);
    }

    public function test_tenant_comes_from_the_trusted_intent_not_the_request_host(): void
    {
        $f = $this->fixtures();
        $invoice = $this->issue($f);
        $intent = $this->startIntent($f['payer'], $invoice);

        $other = Tenant::create(['slug' => 'other-school', 'name' => 'Other', 'locale' => 'ka', 'timezone' => 'Asia/Tbilisi', 'is_active' => true]);
        TenantDomain::create(['tenant_id' => $other->id, 'domain' => 'other-school.test', 'is_primary' => true]);

        // Delivered to another school's host: still credited to the intent's own tenant.
        $this->webhook('payment.succeeded', $this->successFor($intent), null, 'other-school.test')->assertJson(['outcome' => 'processed']);

        $payment = Payment::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame($f['tenant']->id, $payment->tenant_id);
        $this->assertSame($f['tenant']->id, ProviderWebhookEvent::query()->where('outcome', 'processed')->value('tenant_id'));

        // The other school's staff cannot open this invoice.
        $outsider = User::factory()->create();
        $outsider->forceFill(['two_factor_confirmed_at' => now()])->save();
        TenantMembership::create(['tenant_id' => $other->id, 'user_id' => $outsider->id, 'role' => TenantMembership::ROLE_ACCOUNTANT, 'is_active' => true]);
        FeatureEntitlement::create(['tenant_id' => $other->id, 'feature' => 'finance', 'is_enabled' => true]);
        $this->actingAs($outsider)->get("http://other-school.test/portal/finance/invoices/{$invoice->id}", ['Host' => 'other-school.test'])->assertNotFound();
    }
}
