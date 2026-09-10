<?php

namespace App\Domain\Tenancy;

use App\Domain\Tenancy\Models\Tenant;
use App\Http\Middleware\ResolveTenant;

/**
 * Holds the tenant resolved for the current request/job lifecycle.
 *
 * This is the ONLY place tenant identity should come from at runtime — it is
 * populated exclusively by {@see ResolveTenant} (from the
 * trusted request host) or by queue jobs that explicitly carry a tenant id.
 * Client-supplied tenant_id values (query params, form fields, JSON body)
 * must never be written here or trusted anywhere else in the app.
 */
class CurrentTenant
{
    private ?Tenant $tenant = null;

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->id;
    }

    /**
     * Clear the resolved tenant. Queue workers MUST call this after finishing
     * a job so one school's context can never leak into the next job picked
     * up by the same worker process.
     */
    public function clear(): void
    {
        $this->tenant = null;
    }

    public function isResolved(): bool
    {
        return $this->tenant !== null;
    }
}
