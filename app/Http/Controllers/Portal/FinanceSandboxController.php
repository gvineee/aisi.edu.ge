<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Finance\Actions\ProcessPayments;
use App\Domain\Finance\Models\PaymentIntent;
use App\Domain\Finance\Models\Refund;
use App\Domain\Finance\Providers\SandboxPaymentProvider;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SANDBOX checkout — no real money ever moves. "Simulating" a result builds
 * a signed provider event and runs it through exactly the same webhook
 * handler a real bank notification would use.
 */
class FinanceSandboxController extends Controller
{
    public function checkout(Request $request, CurrentTenant $currentTenant, PaymentIntent $intent): Response
    {
        $tenant = $currentTenant->get();
        abort_unless((bool) config('finance.providers.sandbox.enabled') && $intent->tenant_id === $tenant->id && $intent->provider === 'sandbox', 404);
        abort_unless($intent->created_by === $request->user()->id, 403);

        return Inertia::render('portal/finance/sandbox-checkout', [
            'intent' => ['id' => $intent->id, 'status' => $intent->status, 'amountMinor' => $intent->amount_minor, 'currency' => $intent->currency],
        ]);
    }

    public function simulate(Request $request, CurrentTenant $currentTenant, PaymentIntent $intent, SandboxPaymentProvider $sandbox, ProcessPayments $payments): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless((bool) config('finance.providers.sandbox.enabled') && $intent->tenant_id === $tenant->id && $intent->provider === 'sandbox', 404);
        abort_unless($intent->created_by === $request->user()->id, 403);
        $request->validate(['outcome' => ['required', 'in:success,failure']]);

        $event = $request->string('outcome')->toString() === 'success'
            ? $sandbox->makeEvent('payment.succeeded', [
                'reference' => $intent->provider_reference,
                'payment_id' => 'sbxp_'.bin2hex(random_bytes(12)),
                'amount_minor' => $intent->amount_minor,
                'currency' => $intent->currency,
            ])
            : $sandbox->makeEvent('payment.failed', ['reference' => $intent->provider_reference]);

        $payments->handleWebhook($sandbox, $event['body'], $event['signature']);

        return redirect()->route('finance.return', $intent);
    }

    /**
     * Staff-side: confirm a requested sandbox refund as the provider would.
     */
    public function confirmRefund(Request $request, CurrentTenant $currentTenant, Refund $refund, SandboxPaymentProvider $sandbox, ProcessPayments $payments): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless((bool) config('finance.providers.sandbox.enabled') && $refund->tenant_id === $tenant->id, 404);
        abort_unless(TenantMembership::userHasAnyActiveRole($tenant->id, $request->user()->id, FinanceController::STAFF_ROLES) && $request->user()->two_factor_confirmed_at !== null, 403);

        $event = $sandbox->makeEvent('refund.succeeded', ['refund_id' => $refund->provider_refund_id, 'amount_minor' => $refund->amount_minor, 'currency' => $refund->currency]);
        $payments->handleWebhook($sandbox, $event['body'], $event['signature']);

        return back();
    }
}
