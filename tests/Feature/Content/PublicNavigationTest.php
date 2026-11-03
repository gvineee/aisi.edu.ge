<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Models\Page;
use App\Domain\Content\NavigationBlueprint;
use App\Domain\Tenancy\Models\BrandSetting;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public menu (header dropdowns, mobile accordion, footer links) comes
 * from the school's brand settings and never links to a page a visitor
 * cannot open.
 */
class PublicNavigationTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): Tenant
    {
        return TenantDomain::query()->where('domain', 'localhost')->first()->tenant;
    }

    private function publish(Tenant $tenant, string $slug, string $status = Page::STATUS_PUBLISHED): void
    {
        $tenant->pages()->create([
            'slug' => $slug, 'locale' => $tenant->locale, 'title' => $slug,
            'blocks' => [], 'status' => $status,
            'published_at' => $status === Page::STATUS_PUBLISHED ? now() : null,
        ]);
    }

    /** @param  array<string, mixed>  $navigation */
    private function setNavigation(Tenant $tenant, array $navigation): void
    {
        BrandSetting::query()->updateOrCreate(
            ['tenant_id' => $tenant->id],
            ['display_name' => 'სკოლა', 'colors' => [], 'font_family' => 'Noto Sans Georgian', 'navigation' => $navigation],
        );
    }

    public function test_legacy_menu_links_only_published_pages_and_falls_back_to_the_canonical_one(): void
    {
        $tenant = $this->tenant();
        $this->setNavigation($tenant, NavigationBlueprint::definition());
        $this->publish($tenant, 'official-documents');
        $this->publish($tenant, 'about');
        $this->publish($tenant, 'import-wp-pages-1205', Page::STATUS_DRAFT);
        $this->publish($tenant, 'import-wp-pages-1225');

        $this->get('/news')->assertOk()->assertInertia(function ($page) {
            $page->where('publicNavigation.main.0.label', 'სკოლა')
                ->where('publicNavigation.main.0.children.0.label', 'აკრედიტაცია')
                ->where('publicNavigation.main.0.children.0.children', [
                    ['label' => 'დოკუმენტები', 'href' => '/official-documents', 'external' => false],
                    ['label' => 'ონლაინ ბიბლიოთეკა', 'href' => '/library', 'external' => false],
                    // Mission page not imported: the canonical about page stands in.
                    ['label' => 'სკოლის მისია', 'href' => '/about', 'external' => false],
                    // Regulations (draft) and history (missing) are left out.
                    ['label' => 'დირექტორის მისალმება', 'href' => '/import-wp-pages-1225', 'external' => false],
                    ['label' => 'ელექტრონული ჟურნალი', 'href' => 'https://aisi.edupage.org/', 'external' => true],
                ])
                // „ავტორიზაცია“ is unpublished and „ჩარიცხვის წესი“ has no page: the group disappears.
                ->where('publicNavigation.main.1.label', 'ჩვენი გუნდი')
                ->where('publicNavigation.main.1.children', [
                    ['label' => 'მასწავლებლები', 'href' => '/teachers', 'external' => false],
                ])
                ->where('publicNavigation.tagline', 'აქ იწყება შენი ხვალ.');
        });
    }

    public function test_another_schools_published_pages_never_satisfy_a_menu_entry(): void
    {
        $tenant = $this->tenant();
        $this->setNavigation($tenant, ['main' => [['label' => 'რეგულაციები', 'pages' => ['import-wp-pages-1205']]], 'utility' => []]);

        $other = Tenant::create(['slug' => 'other-school', 'name' => 'Other', 'locale' => 'ka', 'timezone' => 'Asia/Tbilisi', 'is_active' => true]);
        $this->publish($other, 'import-wp-pages-1205');

        $this->get('/news')->assertOk()->assertInertia(fn ($page) => $page->where('publicNavigation.main', []));
    }

    public function test_unsafe_or_malformed_entries_are_dropped(): void
    {
        $tenant = $this->tenant();
        $this->setNavigation($tenant, ['main' => [
            ['label' => 'Script', 'url' => 'javascript:alert(1)'],
            ['label' => 'Plain http', 'url' => 'http://example.com'],
            ['label' => 'Protocol-relative', 'path' => '//evil.example'],
            ['label' => '', 'path' => '/news'],
            ['label' => 'Deep', 'children' => [['label' => 'a', 'children' => [['label' => 'b', 'children' => [['label' => 'c', 'children' => [['label' => 'd', 'path' => '/news']]]]]]]]],
            ['label' => 'News', 'path' => '/news'],
        ], 'utility' => []]);

        $this->get('/news')->assertOk()->assertInertia(fn ($page) => $page
            ->where('publicNavigation.main', [['label' => 'News', 'href' => '/news', 'external' => false]]));
    }

    public function test_a_school_without_its_own_menu_gets_the_standard_pages(): void
    {
        $tenant = $this->tenant();
        BrandSetting::query()->where('tenant_id', $tenant->id)->update(['navigation' => null]);
        $this->publish($tenant, 'about');

        $this->get('/news')->assertOk()->assertInertia(fn ($page) => $page
            ->where('publicNavigation.main', [
                ['label' => 'სკოლის შესახებ', 'href' => '/about', 'external' => false],
                ['label' => 'ამბები', 'href' => '/news', 'external' => false],
                ['label' => 'ბიბლიოთეკა', 'href' => '/library', 'external' => false],
            ]));
    }

    public function test_refresh_command_writes_and_resets_the_menu(): void
    {
        $tenant = $this->tenant();
        $this->setNavigation($tenant, ['main' => []]);
        $slug = $tenant->slug;

        $this->artisan('content:refresh-navigation', ['--tenant' => $slug])->assertSuccessful();
        $this->assertSame(NavigationBlueprint::definition(), BrandSetting::query()->where('tenant_id', $tenant->id)->first()->navigation);

        $this->artisan('content:refresh-navigation', ['--tenant' => $slug, '--reset' => true])->assertSuccessful();
        $this->assertNull(BrandSetting::query()->where('tenant_id', $tenant->id)->first()->navigation);
    }
}
