<?php

namespace Tests\Feature\Finance;

use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Finance\Models\Invoice;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentClaim;
use App\Domain\Tenancy\Models\FeatureEntitlement;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Without a bank integration a guardian reports a payment; it changes
 * nothing until the accountant confirms it (then it is a manual payment on
 * the invoice) or rejects it with a reason. Only the child's own guardian
 * can report, only finance staff with MFA can decide.
 */
class PaymentClaimTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, User> */
    private array $u = [];

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        $tenant = TenantDomain::query()->where('domain', 'localhost')->firstOrFail()->tenant;
        FeatureEntitlement::query()->updateOrCreate(['tenant_id' => $tenant->id, 'feature' => 'finance'], ['is_enabled' => true]);
        $year = $tenant->academicYears()->create(['name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-15', 'is_current' => true]);
        $class = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => 'TEST-VI']);

        foreach (['accountant' => TenantMembership::ROLE_ACCOUNTANT, 'parent' => TenantMembership::ROLE_GUARDIAN, 'stranger' => TenantMembership::ROLE_GUARDIAN] as $key => $role) {
            $this->u[$key] = User::factory()->create();
            TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $this->u[$key]->id, 'role' => $role, 'is_active' => true]);
        }
        $this->u['accountant']->forceFill(['two_factor_confirmed_at' => now()])->save();

        $child = $tenant->students()->create(['school_class_id' => $class->id, 'first_name' => 'ტესტ', 'last_name' => 'მოსწავლე', 'is_active' => true]);
        $link = new GuardianLink(['user_id' => $this->u['parent']->id, 'student_id' => $child->id, 'can_view_academic' => true, 'can_view_financial' => true, 'can_pickup' => false, 'can_receive_notifications' => true, 'is_active' => true]);
        $link->tenant_id = $tenant->id;
        $link->save();

        $this->actingAs($this->u['accountant'])->post('/portal/finance/invoices', [
            'student_id' => $child->id,
            'due_on' => now('Asia/Tbilisi')->addDays(10)->toDateString(),
            'items' => [['description' => 'საფასური', 'quantity' => 1, 'unit' => '300.00']],
        ])->assertSessionHasNoErrors();
        $this->invoice = Invoice::query()->latest('id')->firstOrFail();
    }

    private function claim(User $as, array $data = []): TestResponse
    {
        return $this->actingAs($as)->post("/portal/finance/invoices/{$this->invoice->id}/claims", $data + [
            'amount' => '300.00', 'method' => 'bank_transfer', 'paid_on' => now('Asia/Tbilisi')->toDateString(), 'reference' => 'TBC-123456',
        ]);
    }

    public function test_a_guardian_reports_a_payment_and_nothing_is_paid_until_the_accountant_confirms(): void
    {
        $this->claim($this->u['parent'])->assertSessionHasNoErrors();
        $claim = PaymentClaim::query()->sole();
        $this->assertSame([PaymentClaim::STATUS_PENDING, 30000], [$claim->status, $claim->amount_minor]);
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(Invoice::STATUS_ISSUED, $this->invoice->fresh()->status);

        $this->actingAs($this->u['accountant'])->get('/portal/finance')->assertOk()
            ->assertInertia(fn ($page) => $page->has('staff.pendingClaims', 1));

        // The statement shows 280 — the accountant books what arrived.
        $this->actingAs($this->u['accountant'])->post("/portal/finance/claims/{$claim->id}/confirm", ['amount' => '280.00'])->assertSessionHasNoErrors();
        $payment = Payment::query()->sole();
        $this->assertSame([28000, 'TBC-123456', Payment::PROVIDER_MANUAL], [$payment->amount_minor, $payment->reference, $payment->provider]);
        $this->assertSame(Invoice::STATUS_PARTIALLY_PAID, $this->invoice->fresh()->status);
        $this->assertSame([PaymentClaim::STATUS_CONFIRMED, $payment->id], [$claim->fresh()->status, $claim->fresh()->payment_id]);

        // Deciding twice does nothing.
        $this->actingAs($this->u['accountant'])->post("/portal/finance/claims/{$claim->id}/confirm")->assertSessionHasErrors('claim');
        $this->assertSame(1, Payment::query()->count());

        $this->actingAs($this->u['parent'])->get("/portal/finance/invoices/{$this->invoice->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('claims.0.status', 'confirmed')->where('claims.0.submittedBy', null));
    }

    public function test_a_rejection_needs_a_reason_and_leaves_the_invoice_open(): void
    {
        $this->claim($this->u['parent']);
        $claim = PaymentClaim::query()->sole();

        $this->actingAs($this->u['accountant'])->post("/portal/finance/claims/{$claim->id}/reject", ['review_note' => ''])->assertSessionHasErrors('review_note');
        $this->actingAs($this->u['accountant'])->post("/portal/finance/claims/{$claim->id}/reject", ['review_note' => 'ამონაწერში ვერ ვიპოვეთ'])->assertSessionHasNoErrors();

        $this->assertSame([PaymentClaim::STATUS_REJECTED, 'ამონაწერში ვერ ვიპოვეთ'], [$claim->fresh()->status, $claim->fresh()->review_note]);
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_only_the_childs_guardian_reports_only_finance_staff_with_mfa_decide_and_amounts_are_bounded(): void
    {
        $this->claim($this->u['stranger'])->assertForbidden();
        $this->claim($this->u['accountant'])->assertForbidden();
        $this->claim($this->u['parent'], ['amount' => '301.00'])->assertSessionHasErrors('amount');
        $this->claim($this->u['parent'], ['reference' => ''])->assertSessionHasErrors('reference');
        $this->claim($this->u['parent'], ['method' => 'cash', 'reference' => '', 'amount' => '200.00'])->assertSessionHasNoErrors();
        // Pending notices may not exceed what is owed.
        $this->claim($this->u['parent'], ['amount' => '150.00'])->assertSessionHasErrors('amount');

        $claim = PaymentClaim::query()->sole();
        $this->actingAs($this->u['parent'])->post("/portal/finance/claims/{$claim->id}/confirm")->assertForbidden();
        $this->u['accountant']->forceFill(['two_factor_confirmed_at' => null])->save();
        $this->actingAs($this->u['accountant'])->post("/portal/finance/claims/{$claim->id}/confirm")->assertForbidden();
    }
}
