<?php

namespace App\Domain\Finance\Providers;

use App\Domain\Finance\Models\PaymentIntent;

/**
 * Contract every payment provider adapter implements. A real bank adapter
 * is added only once its API terms and credentials exist; until then the
 * sandbox is the sole implementation.
 */
interface PaymentProvider
{
    public function name(): string;

    /**
     * A fresh, unguessable merchant-side reference for a new intent.
     */
    public function newReference(): string;

    /**
     * Where to send the payer to complete the intent.
     */
    public function checkoutUrl(PaymentIntent $intent): string;

    /**
     * Constant-time verification of the raw request body's signature.
     */
    public function verifySignature(string $rawBody, ?string $signature): bool;

    /**
     * Normalised event: event_id, type, reference, provider_payment_id,
     * provider_refund_id, amount_minor, currency. Returns null for a body
     * that cannot be parsed.
     *
     * @return array{event_id: string, type: string, reference: string|null, provider_payment_id: string|null, provider_refund_id: string|null, amount_minor: int|null, currency: string|null}|null
     */
    public function parse(string $rawBody): ?array;

    /**
     * Asks the provider to refund; the result arrives later by webhook.
     */
    public function requestRefund(string $providerPaymentId, int $amountMinor, string $currency): string;
}
