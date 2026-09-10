<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Models\Page;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SitemapTest extends TestCase
{
    use RefreshDatabase;

    public function test_sitemap_only_lists_the_current_tenants_published_pages(): void
    {
        $localTenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;

        $localTenant->pages()->create([
            'slug' => 'home', 'locale' => 'ka', 'title' => 'Home',
            'blocks' => [], 'status' => Page::STATUS_PUBLISHED, 'published_at' => now(),
        ]);
        $localTenant->pages()->create([
            'slug' => 'draft-page', 'locale' => 'ka', 'title' => 'Draft',
            'blocks' => [], 'status' => Page::STATUS_DRAFT, 'published_at' => null,
        ]);

        $otherTenant = Tenant::create([
            'slug' => 'other', 'name' => 'Other', 'locale' => 'ka',
            'timezone' => 'UTC', 'is_active' => true,
        ]);
        TenantDomain::create([
            'tenant_id' => $otherTenant->id, 'domain' => 'other.test', 'is_primary' => true,
        ]);
        $otherTenant->pages()->create([
            'slug' => 'other-tenant-page', 'locale' => 'ka', 'title' => 'Other',
            'blocks' => [], 'status' => Page::STATUS_PUBLISHED, 'published_at' => now(),
        ]);

        $response = $this->get(route('sitemap'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/xml');
        $response->assertDontSee('draft-page');
        $response->assertDontSee('other-tenant-page');
        $response->assertSee(url('/'), false);
    }

    public function test_robots_txt_references_the_sitemap(): void
    {
        $response = $this->get(route('robots'));

        $response->assertOk();
        $response->assertSee('Sitemap: '.url('/sitemap.xml'));
    }
}
