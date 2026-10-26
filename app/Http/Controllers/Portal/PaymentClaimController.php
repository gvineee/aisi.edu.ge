<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Finance\Actions\ManagePaymentClaims;
use App\Domain\Finance\Models\Invoice;
use App\Domain\Finance\Models\PaymentClaim;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Guardians report a payment for their child's invoice; finance staff
 * (with MFA, like every other finance write) confirm or reject it.
 */
class PaymentClaimController extends Controller
{
    public function store(Request $request, CurrentTenant $currentTenant, Invoice $invoice, ManagePaymentClaims $claims): RedirectResponse
    {
        $tenantId = $currentTenant->get()->id;
        abort_unless($invoice->tenant_id === $tenantId, 404);
        $user = $request->user();
        $invoice->loadMissing('account');

        $isGuardian = TenantMembership::userHasActiveRole($tenantId, $user->id, TenantMembership::ROLE_GUARDIAN)
            && GuardianLink::query()->where('tenant_id', $tenantId)->where('user_id', $user->id)->where('is_active', true)
                ->where('can_view_financial', true)->where('student_id', $invoice->account->student_id)->exists();
        abort_unless($isGuardian, 403);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
            'method' => ['required', 'in:'.implode(',', PaymentClaim::METHODS)],
            'paid_on' => ['required', 'date_format:Y-m-d'],
            'reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $claims->submit($invoice, $user, (int) round(((float) $data['amount']) * 100), $data['method'], Carbon::parse($data['paid_on'], 'Asia/Tbilisi'), $data['reference'] ?? null, $data['note'] ?? null);

        return back()->with('toast', ['type' => 'success', 'message' => 'შეტყობინება გაიგზავნა. ბუღალტერია ამონაწერთან შეამოწმებს და დაადასტურებს.']);
    }

    public function confirm(Request $request, CurrentTenant $currentTenant, PaymentClaim $paymentClaim, ManagePaymentClaims $claims): RedirectResponse
    {
        $this->authorizeStaff($request, $currentTenant, $paymentClaim);
        $data = $request->validate(['amount' => ['nullable', 'numeric', 'min:0.01', 'max:1000000']]);
        $amount = isset($data['amount']) ? (int) round(((float) $data['amount']) * 100) : null;

        $claims->confirm($paymentClaim, $request->user(), $amount);

        return back()->with('toast', ['type' => 'success', 'message' => 'გადახდა დადასტურდა და ინვოისზე გატარდა.']);
    }

    public function reject(Request $request, CurrentTenant $currentTenant, PaymentClaim $paymentClaim, ManagePaymentClaims $claims): RedirectResponse
    {
        $this->authorizeStaff($request, $currentTenant, $paymentClaim);
        $data = $request->validate(['review_note' => ['required', 'string', 'max:500']]);

        $claims->reject($paymentClaim, $request->user(), $data['review_note']);

        return back()->with('toast', ['type' => 'success', 'message' => 'შეტყობინება უარყოფილია.']);
    }

    private function authorizeStaff(Request $request, CurrentTenant $currentTenant, PaymentClaim $claim): void
    {
        $tenantId = $currentTenant->get()->id;
        abort_unless($claim->tenant_id === $tenantId, 404);
        $user = $request->user();
        abort_unless(TenantMembership::userHasAnyActiveRole($tenantId, $user->id, FinanceController::STAFF_ROLES) && $user->two_factor_confirmed_at !== null, 403);
    }
}
