<?php

namespace App\Domain\Finance\Providers;

use App\Domain\Finance\Models\PaymentIntent;
use Illuminate\Support\Str;

/**
 * SANDBOX — moves no real money. Signs its webhook bodies with HMAC-SHA256
 * exactly like a real provider would, so the production webhook path
 * (signature check, idempotency, amount/currency validation, allocation)
 * is exercised end to end.
 */
class SandboxPaymentProvider implements PaymentProvider
{
    public const SIGNATURE_HEADER = 'X-Sandbox-Signature';

    public function name(): string
    {
        return 'sandbox';
    }

    public function newReference(): string
    {
        return 'sbx_'.Str::random(32);
    }

    public function checkoutUrl(PaymentIntent $intent): string
    {
        return route('finance.sandbox.checkout', $intent);
    }

    public function verifySignature(string $rawBody, ?string $signature): bool
    {
        return $signature !== null && hash_equals($this->sign($rawBody), $signature);
    }

    public function parse(string $rawBody): ?array
    {
        $data = json_decode($rawBody, true);

        if (! is_array($data) || ! isset($data['id'], $data['type']) || ! is_string($data['id']) || ! is_string($data['type'])) {
            return null;
        }

        return [
            'event_id' => $data['id'],
            'type' => $data['type'],
            'reference' => isset($data['reference']) ? (string) $data['reference'] : null,
            'provider_payment_id' => isset($data['payment_id']) ? (string) $data['payment_id'] : null,
            'provider_refund_id' => isset($data['refund_id']) ? (string) $data['refund_id'] : null,
            'amount_minor' => isset($data['amount_minor']) && is_int($data['amount_minor']) ? $data['amount_minor'] : null,
            'currency' => isset($data['currency']) ? (string) $data['currency'] : null,
        ];
    }

    public function requestRefund(string $providerPaymentId, int $amountMinor, string $currency): string
    {
        return 'sbxr_'.Str::random(32);
    }

    public function sign(string $rawBody): string
    {
        return hash_hmac('sha256', $rawBody, (string) config('finance.providers.sandbox.secret'));
    }

    /**
     * Builds a signed sandbox webhook body (used by the sandbox checkout
     * simulation and by tests).
     *
     * @param  array<string, mixed>  $fields
     * @return array{body: string, signature: string}
     */
    public function makeEvent(string $type, array $fields): array
    {
        $body = (string) json_encode(['id' => 'evt_'.Str::random(24), 'type' => $type] + $fields);

        return ['body' => $body, 'signature' => $this->sign($body)];
    }
}
