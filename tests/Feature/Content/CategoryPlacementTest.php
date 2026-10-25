<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Actions\PlaceContentCategories;
use App\Domain\Content\Models\ContentCategorizable;
use App\Domain\Content\Models\ContentCategory;
use App\Domain\Content\Models\ContentImportRecord;
use App\Domain\Content\Models\Page;
use App\Domain\Content\Models\Post;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Category placement from the plan, with synthetic content: preview writes
 * nothing, commit only places (text/date/status untouched), reruns are
 * idempotent, manual CMS placements and published-vs-archive are kept as
 * conflicts, and only published items of public categories are shown.
 */
class CategoryPlacementTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = TenantDomain::query()->where('domain', 'localhost')->firstOrFail()->tenant;
    }

    private function page(string $slug, string $title, string $status = Page::STATUS_PUBLISHED, ?string $publishedAt = '2023-06-19 15:42:06'): Page
    {
        return $this->tenant->pages()->create([
            'slug' => $slug, 'locale' => $this->tenant->locale, 'title' => $title, 'excerpt' => null,
            'blocks' => [['type' => 'text', 'heading' => $title, 'body' => 'ორიგინალი ტექსტი']],
            'status' => $status, 'published_at' => $status === Page::STATUS_PUBLISHED ? $publishedAt : null,
        ]);
    }

    private function newsPost(string $slug, string $title, string $status = Post::STATUS_PUBLISHED, string $publishedAt = '2025-12-16 11:56:43'): Post
    {
        return $this->tenant->posts()->create([
            'slug' => $slug, 'locale' => $this->tenant->locale, 'title' => $title, 'excerpt' => null,
            'body' => 'დოკუმენტის ტექსტი', 'status' => $status, 'published_at' => $publishedAt,
        ]);
    }

    private function tracked(string $key, ?Model $target, string $status = ContentImportRecord::STATUS_IMPORTED): void
    {
        $record = new ContentImportRecord([
            'source_key' => $key, 'source_type' => 'pages', 'source_url' => "https://example.test/{$key}",
            'importable_type' => $target ? $target::class : null, 'importable_id' => $target?->getKey(),
            'import_batch_id' => (string) Str::uuid(), 'review_status' => $status,
        ]);
        $record->tenant_id = $this->tenant->id;
        $record->save();
    }

    /**
     * @param  list<array{0: string, 1: string, 2?: string}>  $rows
     * @return array<string, mixed>
     */
    private function plan(array $rows): array
    {
        return ['schema_version' => 1, 'records' => array_map(fn (array $row) => [
            'source_key' => $row[0], 'proposed_category' => $row[1], 'proposed_section' => $row[2] ?? null,
            'source_url' => "https://example.test/{$row[0]}", 'original_title' => null, 'original_published_at' => null,
        ], $rows)];
    }

    private function place(array $plan, bool $commit): array
    {
        return app(PlaceContentCategories::class)->handle($this->tenant, $plan, $commit);
    }

    public function test_preview_writes_nothing_and_commit_only_places_idempotently(): void
    {
        $authorization = $this->page('import-wp-pages-2337', 'ავტორიზაცია');
        $report = $this->newsPost('import-wp-posts-2438', 'ფინანსური ანგარიში');
        $this->tracked('wp-pages-2337', $authorization);
        $this->tracked('wp-posts-2438', $report);
        $plan = $this->plan([['wp-pages-2337', 'about', 'authorization'], ['wp-posts-2438', 'documents', 'school-governance']]);
        $before = [$authorization->fresh()->toArray(), $report->fresh()->toArray()];

        $preview = $this->place($plan, false);
        $this->assertSame(['placed' => 2], $preview['counts']);
        $this->assertSame(0, ContentCategorizable::query()->count());
        $this->assertSame(0, ContentCategory::query()->count(), 'preview leaves no categories behind');

        $this->place($plan, true);
        $this->assertSame(2, ContentCategorizable::query()->count());
        $this->assertSame($before, [$authorization->fresh()->toArray(), $report->fresh()->toArray()], 'text, dates and status untouched');
        $this->assertDatabaseHas('audit_events', ['action' => 'content.categories_placed']);

        $rerun = $this->place($plan, true);
        $this->assertSame(['unchanged' => 2], $rerun['counts']);
    }

    public function test_conflicts_pending_archive_and_unknown_records_are_reported_not_forced(): void
    {
        $published = $this->newsPost('import-wp-posts-40', 'UK Students Celebrate');
        $manual = $this->page('import-wp-pages-1205', 'შიდა რეგულაციები');
        $this->tracked('wp-posts-40', $published);
        $this->tracked('wp-pages-1205', $manual);
        $this->tracked('wp-pages-54', null, ContentImportRecord::STATUS_BLOCKED);
        $this->tracked('wp-pages-999', null, ContentImportRecord::STATUS_BLOCKED);

        // An editor already placed the regulations page by hand.
        ContentCategory::ensureDefaults($this->tenant->id);
        $about = ContentCategory::query()->where('slug', 'about')->sole();
        $placement = new ContentCategorizable(['content_category_id' => $about->id, 'categorizable_type' => Page::class, 'categorizable_id' => $manual->id, 'placed_by_source' => 'cms:user:1']);
        $placement->tenant_id = $this->tenant->id;
        $placement->save();

        $result = $this->place($this->plan([
            ['wp-posts-40', 'archive-review'],
            ['wp-pages-1205', 'documents', 'regulations'],
            ['wp-pages-54', 'archive-review'],
            ['wp-pages-999', 'about'],
            ['nope', 'news'],
        ]), true);

        $this->assertSame(['conflict', 'conflict', 'archived', 'pending', 'error'], array_column($result['rows'], 'disposition'));
        $this->assertSame(Post::STATUS_PUBLISHED, $published->fresh()->status, 'never unpublished');
        $this->assertSame($about->id, $placement->fresh()->content_category_id, 'manual placement kept');
    }

    public function test_hubs_list_only_published_items_and_documents_leave_the_news_list(): void
    {
        $this->page('official-documents', 'დოკუმენტები');
        $news = $this->newsPost('import-wp-posts-2462', 'წყლის დღე', Post::STATUS_PUBLISHED, '2026-03-27 07:36:36');
        $document = $this->newsPost('import-wp-posts-2438', 'ფინანსური ანგარიში');
        $draft = $this->newsPost('import-wp-posts-2429', 'სამოქმედო გეგმა', Post::STATUS_DRAFT);
        $template = $this->newsPost('import-wp-posts-13', 'Tuition Fees', Post::STATUS_DRAFT);
        foreach (['wp-posts-2462' => $news, 'wp-posts-2438' => $document, 'wp-posts-2429' => $draft, 'wp-posts-13' => $template] as $key => $target) {
            $this->tracked($key, $target);
        }
        $this->place($this->plan([
            ['wp-posts-2462', 'news'], ['wp-posts-2438', 'documents'], ['wp-posts-2429', 'documents'], ['wp-posts-13', 'archive-review'],
        ]), true);

        $hub = $this->get('/official-documents')->assertOk();
        $hub->assertInertia(fn ($page) => $page
            ->where('categorySection.name', 'დოკუმენტები')
            ->has('categorySection.items', 1)
            ->where('categorySection.items.0.url', '/news/import-wp-posts-2438')
            ->where('categorySection.items.0.date', $document->published_at->toIso8601String()));
        // Crawlers get the same list in the initial HTML.
        $hub->assertSee('href="/news/import-wp-posts-2438"', false);

        $this->get('/news')->assertInertia(fn ($page) => $page->has('posts.data', 1)->where('posts.data.0.slug', 'import-wp-posts-2462'));
        $this->get('/news/import-wp-posts-2438')->assertOk()
            ->assertInertia(fn ($page) => $page->where('breadcrumb.href', '/official-documents'));
        $this->get('/news/import-wp-posts-13')->assertNotFound();
    }

    public function test_an_editor_moves_an_item_and_the_plan_then_leaves_it_alone(): void
    {
        $page = $this->page('import-wp-pages-1225', 'დირექტორის მისალმება');
        $this->tracked('wp-pages-1225', $page);
        $editor = User::factory()->create();
        TenantMembership::create(['tenant_id' => $this->tenant->id, 'user_id' => $editor->id, 'role' => TenantMembership::ROLE_EDITOR, 'is_active' => true]);
        $teacher = User::factory()->create();
        TenantMembership::create(['tenant_id' => $this->tenant->id, 'user_id' => $teacher->id, 'role' => TenantMembership::ROLE_TEACHER, 'is_active' => true]);
        ContentCategory::ensureDefaults($this->tenant->id);
        $admissions = ContentCategory::query()->where('slug', 'admissions')->sole();

        $this->actingAs($teacher)->put("/portal/cms/placements/pages/{$page->id}", ['content_category_id' => $admissions->id])->assertForbidden();
        $this->actingAs($editor)->put("/portal/cms/placements/pages/{$page->id}", ['content_category_id' => $admissions->id])->assertSessionHasNoErrors();

        $this->assertSame($admissions->id, $page->placement()->first()->content_category_id);
        $this->assertSame('conflict', $this->place($this->plan([['wp-pages-1225', 'about', 'director-welcome']]), true)['rows'][0]['disposition']);

        // Another school's page is out of reach.
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'locale' => 'ka', 'timezone' => 'Asia/Tbilisi', 'is_active' => true]);
        $foreign = $other->pages()->create(['slug' => 'x', 'locale' => 'ka', 'title' => 'x', 'blocks' => [], 'status' => Page::STATUS_DRAFT]);
        $this->actingAs($editor)->put("/portal/cms/placements/pages/{$foreign->id}", ['content_category_id' => $admissions->id])->assertNotFound();
    }
}
