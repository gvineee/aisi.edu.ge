<?php

namespace Tests\Feature\Library;

use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LibraryCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_filters_by_title_author_or_subject(): void
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;

        $tenant->libraryResources()->create([
            'title' => 'მათემატიკა', 'author' => 'ავტორი', 'grade' => 'VI', 'subject' => 'მათემატიკა',
            'is_required' => true, 'access_scope' => 'catalog_only',
        ]);
        $tenant->libraryResources()->create([
            'title' => 'ისტორია', 'author' => 'სხვა ავტორი', 'grade' => 'VI', 'subject' => 'ისტორია',
            'is_required' => false, 'access_scope' => 'loan',
        ]);

        $response = $this->get('/library?q='.urlencode('მათემატიკა'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('resources.data.0.title', 'მათემატიკა')
            ->count('resources.data', 1)
        );
    }

    public function test_no_results_gives_an_empty_data_set_not_an_error(): void
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;

        $tenant->libraryResources()->create([
            'title' => 'მათემატიკა', 'grade' => 'VI', 'subject' => 'მათემატიკა',
            'is_required' => true, 'access_scope' => 'catalog_only',
        ]);

        $response = $this->get('/library?q=nonexistent-subject-xyz');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->count('resources.data', 0));
    }

    public function test_catalog_never_includes_another_tenants_resources(): void
    {
        $localTenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;
        $localTenant->libraryResources()->create([
            'title' => 'Local Resource', 'grade' => 'VI', 'subject' => 'Math',
            'is_required' => true, 'access_scope' => 'catalog_only',
        ]);

        $otherTenant = Tenant::create([
            'slug' => 'other-library-tenant', 'name' => 'Other', 'locale' => 'ka',
            'timezone' => 'UTC', 'is_active' => true,
        ]);
        $otherTenant->libraryResources()->create([
            'title' => 'Other Tenant Resource', 'grade' => 'VI', 'subject' => 'Math',
            'is_required' => true, 'access_scope' => 'catalog_only',
        ]);

        $response = $this->get('/library');

        $response->assertOk();
        $response->assertDontSee('Other Tenant Resource');
    }

    /**
     * Audit A02: 25+ items span three pages and every page, with the query,
     * is reachable by link; only a rights-confirmed digital item gets a link.
     */
    public function test_pages_carry_the_query_and_only_digital_items_link_out(): void
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;

        for ($i = 1; $i <= 26; $i++) {
            $tenant->libraryResources()->create([
                'title' => sprintf('წიგნი %02d', $i), 'grade' => 'VI', 'is_required' => false,
                'access_scope' => $i === 26 ? 'digital' : 'loan',
                'external_url' => 'https://example.org/book-'.$i,
            ]);
        }

        $this->get('/library?q='.urlencode('წიგნი').'&page=3')->assertOk()->assertInertia(fn ($page) => $page
            ->where('resources.current_page', 3)
            ->where('resources.last_page', 3)
            ->where('resources.total', 26)
            ->where('resources.data.1.title', 'წიგნი 26')
            ->where('resources.data.1.externalUrl', 'https://example.org/book-26')
            ->where('resources.data.0.externalUrl', null)
            ->where('resources.prev_page_url', fn ($url) => str_contains((string) $url, 'q=') && str_contains((string) $url, 'page=2'))
        );
    }
}
