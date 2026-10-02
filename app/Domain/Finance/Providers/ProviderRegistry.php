<?php

namespace App\Domain\Finance\Providers;

/**
 * Resolves a provider adapter by name. Only the sandbox exists until a real
 * bank contract and credentials are provided.
 */
class ProviderRegistry
{
    public function __construct(private readonly SandboxPaymentProvider $sandbox) {}

    public function get(string $name): ?PaymentProvider
    {
        return match ($name) {
            'sandbox' => $this->sandbox,
            default => null,
        };
    }

    public function default(): PaymentProvider
    {
        return $this->sandbox;
    }
}
