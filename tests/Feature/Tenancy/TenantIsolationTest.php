<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Content\Models\Page;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers docs/02 critical check #1: a tenant's content is never reachable
 * through another tenant's domain, whether via the global Eloquent scope or
 * an explicit tenant_id-filtered query (the defense-in-depth path every
 * controller/policy is supposed to use per CLAUDE.md's tenancy invariants).
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenantWithHomePage(string $slug, string $domain, string $title): Tenant
    {
        $tenant = Tenant::create([
            'slug' => $slug,
            'name' => $title,
            'locale' => 'ka',
            'timezone' => 'Asia/Tbilisi',
            'is_active' => true,
        ]);

        TenantDomain::create([
            'tenant_id' => $tenant->id,
            'domain' => $domain,
            'is_primary' => true,
        ]);

        $tenant->pages()->create([
            'slug' => 'home',
            'locale' => 'ka',
            'title' => $title,
            'excerpt' => null,
            'blocks' => [['type' => 'hero', 'heading' => $title]],
            'status' => Page::STATUS_PUBLISHED,
            'published_at' => now(),
            'seo_title' => $title,
            'seo_description' => $title,
        ]);

        return $tenant;
    }

    public function test_unknown_host_gets_404_not_any_tenant(): void
    {
        $this->makeTenantWithHomePage('tenant-a', 'tenant-a.test', 'Tenant A');

        $response = $this->get('http://totally-unknown-host.test/');

        $response->assertNotFound();
    }

    public function test_each_domain_only_ever_serves_its_own_tenant_home_page(): void
    {
        $this->makeTenantWithHomePage('tenant-a', 'tenant-a.test', 'Tenant A Home');
        $this->makeTenantWithHomePage('tenant-b', 'tenant-b.test', 'Tenant B Home');

        $this->get('http://tenant-a.test/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('page.title', 'Tenant A Home')
            );

        $this->get('http://tenant-b.test/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('page.title', 'Tenant B Home')
            );
    }

    public function test_global_scope_hides_other_tenants_pages_once_current_tenant_is_set(): void
    {
        $tenantA = $this->makeTenantWithHomePage('tenant-a', 'tenant-a.test', 'Tenant A Home');
        $this->makeTenantWithHomePage('tenant-b', 'tenant-b.test', 'Tenant B Home');

        app(CurrentTenant::class)->set($tenantA);

        $titles = Page::query()->pluck('title')->all();

        $this->assertSame(['Tenant A Home'], $titles);
    }

    public function test_explicit_tenant_id_filter_also_blocks_cross_tenant_lookup(): void
    {
        // This is the defense-in-depth path: even with no global scope
        // active at all (e.g. a console command with no resolved tenant),
        // an explicit ->where('tenant_id', ...) lookup must still refuse a
        // record that belongs to a different tenant.
        $tenantA = $this->makeTenantWithHomePage('tenant-a', 'tenant-a.test', 'Tenant A Home');
        $tenantB = $this->makeTenantWithHomePage('tenant-b', 'tenant-b.test', 'Tenant B Home');

        $tenantBPage = Page::withoutGlobalScopes()
            ->where('tenant_id', $tenantB->id)
            ->firstOrFail();

        $found = Page::withoutGlobalScopes()
            ->where('tenant_id', $tenantA->id)
            ->where('id', $tenantBPage->id)
            ->first();

        $this->assertNull($found);
    }
}
