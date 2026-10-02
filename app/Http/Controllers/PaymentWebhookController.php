<?php

namespace App\Http\Controllers;

use App\Domain\Finance\Actions\ProcessPayments;
use App\Domain\Finance\Providers\ProviderRegistry;
use App\Domain\Finance\Providers\SandboxPaymentProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Provider → server payment notifications. Unauthenticated by design; the
 * signature over the raw body is the authentication. The request host's
 * tenant is ignored — the event is matched to a tenant only through the
 * trusted payment intent it references.
 */
class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request, string $provider, ProviderRegistry $providers, ProcessPayments $payments): JsonResponse
    {
        $adapter = $providers->get($provider);
        abort_if($adapter === null, 404);

        if ($adapter instanceof SandboxPaymentProvider && ! (bool) config('finance.providers.sandbox.enabled')) {
            abort(404);
        }

        $outcome = $payments->handleWebhook($adapter, $request->getContent(), $request->header(SandboxPaymentProvider::SIGNATURE_HEADER));

        // Providers retry on non-2xx; only a bad signature is refused so a
        // valid-but-unmatched event is not retried forever.
        return response()->json(['outcome' => $outcome], $outcome === 'rejected_signature' ? 400 : 200);
    }
}
