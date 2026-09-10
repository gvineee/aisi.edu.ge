<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Models\Page;
use App\Domain\Tenancy\Models\TenantDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CLAUDE.md requires public page title/description/content to be present in
 * the initial HTML without JS. We don't run Inertia SSR (see
 * docs/implementation-status.md), so app.blade.php reads the per-page SEO
 * props directly instead — this test locks that path in.
 */
class HomePageSeoTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_page_seo_title_and_description_are_in_the_raw_html(): void
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;

        $tenant->pages()->create([
            'slug' => 'home',
            'locale' => $tenant->locale,
            'title' => 'მთავარი',
            'excerpt' => null,
            'blocks' => [],
            'status' => Page::STATUS_PUBLISHED,
            'published_at' => now(),
            'seo_title' => 'უნიკალური სათაური საძიებოსთვის',
            'seo_description' => 'უნიკალური აღწერა საძიებოსთვის',
        ]);

        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertSee('<title>უნიკალური სათაური საძიებოსთვის</title>', false);
        $response->assertSee('name="description" content="უნიკალური აღწერა საძიებოსთვის"', false);
    }
}
