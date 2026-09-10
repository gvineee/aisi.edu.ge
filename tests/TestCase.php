<?php

namespace Tests;

use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }

    /**
     * Every request in this app is tenant-scoped (ResolveTenant middleware
     * aborts 404 for an unmapped host), so the default test request host
     * ("localhost", from APP_URL) needs a tenant to resolve to. Tests that
     * exercise tenant isolation directly create their own additional
     * tenants/domains on top of this baseline.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (Schema::hasTable('tenants') && ! TenantDomain::query()->where('domain', 'localhost')->exists()) {
            $tenant = Tenant::create([
                'slug' => 'test-tenant',
                'name' => 'Test Tenant',
                'locale' => 'ka',
                'timezone' => 'Asia/Tbilisi',
                'is_active' => true,
            ]);

            TenantDomain::create([
                'tenant_id' => $tenant->id,
                'domain' => 'localhost',
                'is_primary' => true,
            ]);
        }
    }
}
