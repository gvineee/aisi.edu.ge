<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Models\ContentMediaLink;
use App\Domain\Content\Models\Page;
use App\Domain\Content\NavigationBlueprint;
use App\Domain\Tenancy\Models\BrandSetting;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `content:publish-menu-pages` publishes the previous site's menu pages so
 * the public menu shows them as the old site did, through the CMS's own
 * revision and audit trail, and never the old theme's placeholder page.
 */
class LegacyMenuPagesTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): Tenant
    {
        return TenantDomain::query()->where('domain', 'localhost')->first()->tenant;
    }

    private function admin(Tenant $tenant): User
    {
        $user = User::factory()->create();
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => TenantMembership::ROLE_ADMIN, 'is_active' => true]);

        return $user;
    }

    /** @param  array<int, array<string, mixed>>  $blocks */
    private function draft(Tenant $tenant, string $slug, string $title, array $blocks): Page
    {
        return $tenant->pages()->create([
            'slug' => $slug, 'locale' => $tenant->locale, 'title' => $title,
            'blocks' => $blocks, 'status' => Page::STATUS_DRAFT,
        ]);
    }

    public function test_menu_pages_are_laid_out_published_and_audited_once(): void
    {
        $tenant = $this->tenant();
        $this->admin($tenant);
        $this->draft($tenant, 'import-wp-pages-1225', 'დირექტორის მისალმება', [
            ['type' => 'text', 'heading' => 'დირექტორის მისალმება', 'body' => "დირექტორის მისალმება\n\nძვირფასო  მშობლებო.\n\nმეორე აბზაცი."],
        ]);
        $this->draft($tenant, 'import-wp-pages-1205', 'შიდა რეგულაციები', [
            ['type' => 'text', 'heading' => 'შიდა რეგულაციები', 'body' => "HR-პოლიტიკა\n\nსამედიცინო პოლიტიკის წესი"],
        ]);
        // The old theme's placeholder history page is never published.
        $lorem = $this->draft($tenant, 'import-wp-pages-1241', 'სკოლის ისტორია', [['type' => 'text', 'heading' => 'x', 'body' => 'Lorem ipsum']]);

        $this->artisan('content:publish-menu-pages', ['--tenant' => $tenant->slug, '--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, Page::query()->where('status', Page::STATUS_PUBLISHED)->count());

        $this->artisan('content:publish-menu-pages', ['--tenant' => $tenant->slug])->assertSuccessful();

        $welcome = Page::query()->where('slug', 'import-wp-pages-1225')->first();
        $this->assertTrue($welcome->isPublished());
        $this->assertSame([
            ['type' => 'hero', 'eyebrow' => 'სკოლა · აკრედიტაცია', 'heading' => 'დირექტორის მისალმება'],
            ['type' => 'text', 'body' => ['ძვირფასო მშობლებო.', 'მეორე აბზაცი.']],
        ], $welcome->blocks);
        $this->assertSame(1, $welcome->revisions()->count());
        $this->assertDatabaseHas('audit_events', ['action' => 'page.published', 'subject_id' => $welcome->id]);

        // The regulations page lists the real PDFs, not the import's captions.
        $regulations = Page::query()->where('slug', 'import-wp-pages-1205')->first();
        $this->assertSame('documents', $regulations->blocks[1]['type']);
        $this->assertContains(
            ['title' => 'HR პოლიტიკა', 'url' => '/storage/tenants/aisi/documents/HR-პოლიტიკა.pdf'],
            $regulations->blocks[1]['groups'][0]['items'],
        );

        $this->assertFalse($lorem->fresh()->isPublished());

        // A second run changes nothing and keeps an editor's later edits.
        $edited = $welcome->blocks;
        $edited[1]['body'] = ['რედაქტორის ტექსტი.'];
        $welcome->update(['blocks' => $edited]);
        $this->artisan('content:publish-menu-pages', ['--tenant' => $tenant->slug])->assertSuccessful();
        $this->assertSame($edited, $welcome->fresh()->blocks);
        $this->assertSame(1, $welcome->revisions()->count());
    }

    public function test_a_curated_documents_block_replaces_the_imported_attachment_list(): void
    {
        $tenant = $this->tenant();
        $make = function (string $slug, array $blocks) use ($tenant): void {
            $page = $tenant->pages()->create([
                'slug' => $slug, 'locale' => $tenant->locale, 'title' => $slug,
                'blocks' => $blocks, 'status' => Page::STATUS_PUBLISHED, 'published_at' => now(),
            ]);
            $link = new ContentMediaLink([
                'linkable_type' => Page::class, 'linkable_id' => $page->id, 'role' => ContentMediaLink::ROLE_ATTACHMENT,
                'position' => 0, 'disk_path' => "tenants/x/{$slug}.pdf", 'mime' => 'application/pdf', 'bytes' => 10,
                'sha256' => str_repeat('a', 64), 'source_url' => "https://old.example/{$slug}.pdf", 'resolution' => 'original',
                'label' => 'არასწორი წარწერა',
            ]);
            $link->tenant_id = $tenant->id;
            $link->save();
        };
        $make('curated', [['type' => 'documents', 'groups' => [['heading' => 'წესები', 'items' => [['title' => 'წესი', 'url' => '/storage/a.pdf']]]]]]);
        $make('plain', [['type' => 'text', 'body' => 'ტექსტი']]);

        $this->get('/curated')->assertOk()->assertInertia(fn ($page) => $page->where('media.attachments', []));
        $this->get('/plain')->assertOk()->assertInertia(fn ($page) => $page->where('media.attachments.0.label', 'არასწორი წარწერა'));
    }

    public function test_it_needs_an_admin_to_attribute_the_change_to(): void
    {
        $tenant = $this->tenant();
        $this->draft($tenant, 'import-wp-pages-1225', 'დირექტორის მისალმება', [['type' => 'text', 'heading' => 'x', 'body' => 'ტექსტი']]);

        $this->artisan('content:publish-menu-pages', ['--tenant' => $tenant->slug])->assertFailed();
        $this->assertFalse(Page::query()->where('slug', 'import-wp-pages-1225')->first()->isPublished());
    }

    public function test_published_menu_pages_appear_in_the_menu_and_the_crawler_html(): void
    {
        $tenant = $this->tenant();
        $this->admin($tenant);
        BrandSetting::query()->updateOrCreate(
            ['tenant_id' => $tenant->id],
            ['display_name' => 'სკოლა', 'colors' => [], 'font_family' => 'Noto Sans Georgian', 'navigation' => NavigationBlueprint::definition()],
        );
        $this->draft($tenant, 'import-wp-pages-2313', 'ჩარიცხვის წესი', [['type' => 'text', 'heading' => 'ჩარიცხვის წესი', 'body' => "პირველი.\n\nმეორე."]]);

        $this->artisan('content:publish-menu-pages', ['--tenant' => $tenant->slug])->assertSuccessful();

        $this->get('/import-wp-pages-2313')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('publicNavigation.main.1.label', 'სასწავლო პროცესი')
                ->where('publicNavigation.main.1.children.0', ['label' => 'ჩარიცხვის წესი', 'href' => '/import-wp-pages-2313', 'external' => false])
                ->where('publicNavigation.utility.0', ['label' => 'ჩარიცხვის წესი', 'href' => '/import-wp-pages-2313', 'external' => false]))
            // Crawler HTML: the tenant's menu, the paragraphs, no empty heading.
            ->assertSee('<li><a href="/import-wp-pages-2313">ჩარიცხვის წესი</a></li>', false)
            ->assertSee('<p>პირველი.</p>', false)
            ->assertDontSee('<h2></h2>', false);
    }
}
