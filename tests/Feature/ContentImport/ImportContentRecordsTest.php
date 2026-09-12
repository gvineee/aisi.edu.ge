<?php

namespace Tests\Feature\ContentImport;

use App\Domain\Content\Actions\ChangePostStatus;
use App\Domain\Content\Actions\ImportContentRecords;
use App\Domain\Content\Models\ContentImportRecord;
use App\Domain\Content\Models\Page;
use App\Domain\Content\Models\Post;
use App\Domain\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * docs/08-content-migration.md §6/§7: the importer must be idempotent,
 * tenant-explicit, default-dry-run, and must never silently overwrite a
 * local editorial change or auto-merge known duplicate source pages.
 */
class ImportContentRecordsTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(string $slug): Tenant
    {
        return Tenant::create([
            'slug' => $slug,
            'name' => ucfirst($slug),
            'locale' => 'ka',
            'timezone' => 'Asia/Tbilisi',
            'is_active' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function pageRecord(string $key, int $sourceId, string $title = 'სათაური', string $body = 'ორიგინალი ტექსტი.'): array
    {
        return [
            'key' => $key,
            'source_id' => $sourceId,
            'type' => 'pages',
            'source_url' => "https://aisi.edu.ge/{$sourceId}/",
            'title' => $title,
            'excerpt' => 'excerpt',
            'original_clean_text' => $body,
            'body' => $body,
            'target_path' => "/pages/{$sourceId}",
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function postRecord(string $key, int $sourceId, string $title = 'სიახლე', string $body = 'ორიგინალი სიახლის ტექსტი.', int $featuredMedia = 0, ?string $publishedAt = null): array
    {
        return [
            'key' => $key,
            'source_id' => $sourceId,
            'type' => 'posts',
            'source_url' => "https://aisi.edu.ge/news/{$sourceId}/",
            'title' => $title,
            'excerpt' => 'excerpt',
            'original_clean_text' => $body,
            'body' => $body,
            'target_path' => "/posts/{$sourceId}",
            'featured_media' => $featuredMedia,
            'published_at' => $publishedAt,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function documentRecord(string $key, string $sourceUrl, string $title, string $body = 'ტექსტი.'): array
    {
        return [
            'key' => $key,
            'source_id' => null,
            'type' => 'documents',
            'source_url' => $sourceUrl,
            'title' => $title,
            'excerpt' => 'excerpt',
            'original_clean_text' => $body,
            'body' => $body,
            'target_path' => '/documents/'.$key,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function teacherRecord(string $key, string $name, string $subject = 'ინგლისური ენა'): array
    {
        $body = "{$name}\n\n{$subject}";

        return [
            'key' => $key,
            'source_id' => null,
            'type' => 'teachers',
            'source_url' => 'https://aisi.edu.ge/instructor/'.$key.'/',
            'title' => $name,
            'excerpt' => $body,
            'original_clean_text' => $body,
            'body' => $body,
            'target_path' => '/teachers/'.$key,
        ];
    }

    public function test_dry_run_creates_no_database_rows(): void
    {
        $tenant = $this->makeTenant('dry-run-tenant');
        $records = [$this->pageRecord('wp-pages-1', 101)];

        (new ImportContentRecords)->run($tenant, $records, ['pages', 'posts'], [], (string) Str::uuid(), commit: false);

        $this->assertSame(0, Page::query()->where('tenant_id', $tenant->id)->count());
        $this->assertSame(0, ContentImportRecord::query()->where('tenant_id', $tenant->id)->count());
    }

    public function test_commit_creates_expected_drafts(): void
    {
        $tenant = $this->makeTenant('commit-tenant');
        $records = [
            $this->pageRecord('wp-pages-1', 101, 'გვერდი'),
            $this->postRecord('wp-posts-1', 201, 'სიახლე'),
        ];

        $report = (new ImportContentRecords)->run($tenant, $records, ['pages', 'posts'], [], (string) Str::uuid(), commit: true);

        $this->assertSame(['created', 'created'], array_column($report, 'action'));

        $page = Page::query()->where('tenant_id', $tenant->id)->sole();
        $this->assertSame(Page::STATUS_DRAFT, $page->status);
        $this->assertSame('text', $page->blocks[0]['type']);
        $this->assertSame('ორიგინალი ტექსტი.', $page->blocks[0]['body']);

        $post = Post::query()->where('tenant_id', $tenant->id)->sole();
        $this->assertSame(Post::STATUS_DRAFT, $post->status);
        $this->assertSame('ორიგინალი სიახლის ტექსტი.', $post->body);

        $this->assertSame(2, ContentImportRecord::query()->where('tenant_id', $tenant->id)->count());
    }

    public function test_second_commit_run_is_a_no_op_for_unchanged_records(): void
    {
        $tenant = $this->makeTenant('idempotent-tenant');
        $records = [$this->pageRecord('wp-pages-1', 101)];

        (new ImportContentRecords)->run($tenant, $records, ['pages', 'posts'], [], (string) Str::uuid(), commit: true);
        $firstPageId = Page::query()->where('tenant_id', $tenant->id)->sole()->id;

        $report = (new ImportContentRecords)->run($tenant, $records, ['pages', 'posts'], [], (string) Str::uuid(), commit: true);

        $this->assertSame(['skip_unchanged'], array_column($report, 'action'));
        $this->assertSame(1, Page::query()->where('tenant_id', $tenant->id)->count());
        $this->assertSame($firstPageId, Page::query()->where('tenant_id', $tenant->id)->sole()->id);
        $this->assertSame(1, ContentImportRecord::query()->where('tenant_id', $tenant->id)->count());
    }

    public function test_locally_edited_page_is_flagged_conflict_and_never_overwritten(): void
    {
        $tenant = $this->makeTenant('conflict-tenant');
        $records = [$this->pageRecord('wp-pages-1', 101)];

        (new ImportContentRecords)->run($tenant, $records, ['pages', 'posts'], [], (string) Str::uuid(), commit: true);

        $page = Page::query()->where('tenant_id', $tenant->id)->sole();
        $blocks = $page->blocks;
        $blocks[0]['body'] = 'რედაქტორის მიერ ხელით შესწორებული ტექსტი.';
        $page->blocks = $blocks;
        $page->save();

        // Source also changed since the last import — the conflict check
        // must still win; a local edit is never silently overwritten.
        $changedSourceRecords = [$this->pageRecord('wp-pages-1', 101, body: 'წყაროზე შეცვლილი ტექსტი.')];
        $report = (new ImportContentRecords)->run($tenant, $changedSourceRecords, ['pages', 'posts'], [], (string) Str::uuid(), commit: true);

        $this->assertSame(['conflict'], array_column($report, 'action'));
        $page->refresh();
        $this->assertSame('რედაქტორის მიერ ხელით შესწორებული ტექსტი.', $page->blocks[0]['body']);
    }

    public function test_changed_source_refreshes_untouched_local_draft(): void
    {
        $tenant = $this->makeTenant('refresh-tenant');
        (new ImportContentRecords)->run($tenant, [$this->pageRecord('wp-pages-1', 101, body: 'ძველი ტექსტი.')], ['pages', 'posts'], [], (string) Str::uuid(), commit: true);

        $report = (new ImportContentRecords)->run($tenant, [$this->pageRecord('wp-pages-1', 101, body: 'ახალი ტექსტი წყაროდან.')], ['pages', 'posts'], [], (string) Str::uuid(), commit: true);

        $this->assertSame(['updated'], array_column($report, 'action'));
        $page = Page::query()->where('tenant_id', $tenant->id)->sole();
        $this->assertSame('ახალი ტექსტი წყაროდან.', $page->blocks[0]['body']);
    }

    public function test_tenant_a_import_is_never_visible_to_or_touched_for_tenant_b(): void
    {
        $tenantA = $this->makeTenant('school-a');
        $tenantB = $this->makeTenant('school-b');

        (new ImportContentRecords)->run($tenantA, [$this->pageRecord('wp-pages-1', 101)], ['pages', 'posts'], [], (string) Str::uuid(), commit: true);

        $this->assertSame(1, Page::query()->where('tenant_id', $tenantA->id)->count());
        $this->assertSame(0, Page::query()->where('tenant_id', $tenantB->id)->count());
        $this->assertSame(0, ContentImportRecord::query()->where('tenant_id', $tenantB->id)->count());

        // Importing the identically-keyed source record for tenant B must
        // create tenant B's own row, independent of tenant A's tracking key.
        (new ImportContentRecords)->run($tenantB, [$this->pageRecord('wp-pages-1', 101)], ['pages', 'posts'], [], (string) Str::uuid(), commit: true);
        $this->assertSame(1, Page::query()->where('tenant_id', $tenantB->id)->count());
        $this->assertNotSame(
            Page::query()->where('tenant_id', $tenantA->id)->sole()->id,
            Page::query()->where('tenant_id', $tenantB->id)->sole()->id,
        );
    }

    public function test_merge_candidate_groups_are_flagged_not_auto_merged(): void
    {
        $tenant = $this->makeTenant('merge-tenant');
        $records = [
            $this->pageRecord('wp-pages-2371', 2371, 'სკოლის ისტორია'),
            $this->pageRecord('wp-pages-1241', 1241, 'სკოლის ისტორია'),
            $this->pageRecord('wp-pages-2159', 2159, 'სკოლის ისტორიაზ'),
        ];

        $report = (new ImportContentRecords)->run($tenant, $records, ['pages', 'posts'], [], (string) Str::uuid(), commit: true);

        foreach ($report as $row) {
            $this->assertSame('about-history', $row['merge_group']);
            $this->assertSame('created', $row['action']);
        }
        // All three become separate drafts — nothing is auto-merged.
        $this->assertSame(3, Page::query()->where('tenant_id', $tenant->id)->count());
    }

    public function test_blocked_source_id_is_never_imported_as_a_normal_page(): void
    {
        $tenant = $this->makeTenant('blocked-tenant');
        $records = [$this->pageRecord('wp-pages-54', 54, 'აკრედიტაცია')];

        $report = (new ImportContentRecords)->run($tenant, $records, ['pages', 'posts'], [], (string) Str::uuid(), commit: true);

        $this->assertSame(['blocked'], array_column($report, 'action'));
        $this->assertSame(0, Page::query()->where('tenant_id', $tenant->id)->count());
        $this->assertSame(
            ContentImportRecord::STATUS_BLOCKED,
            ContentImportRecord::query()->where('tenant_id', $tenant->id)->sole()->review_status,
        );
    }

    public function test_second_commit_run_over_a_blocked_record_does_not_duplicate_or_crash(): void
    {
        $tenant = $this->makeTenant('blocked-rerun-tenant');
        $records = [$this->pageRecord('wp-pages-54', 54, 'აკრედიტაცია')];

        (new ImportContentRecords)->run($tenant, $records, ['pages', 'posts'], [], (string) Str::uuid(), commit: true);
        $report = (new ImportContentRecords)->run($tenant, $records, ['pages', 'posts'], [], (string) Str::uuid(), commit: true);

        $this->assertSame(['blocked'], array_column($report, 'action'));
        $this->assertSame(1, ContentImportRecord::query()->where('tenant_id', $tenant->id)->count());
    }

    public function test_record_listed_in_failures_json_is_blocked_not_fabricated(): void
    {
        $tenant = $this->makeTenant('failed-collect-tenant');
        $records = [$this->pageRecord('wp-pages-9', 9)];

        $report = (new ImportContentRecords)->run($tenant, $records, ['pages', 'posts'], failedUrls: ['https://aisi.edu.ge/9/'], importBatchId: (string) Str::uuid(), commit: true);

        $this->assertSame(['blocked'], array_column($report, 'action'));
        $this->assertSame(0, Page::query()->where('tenant_id', $tenant->id)->count());
    }

    public function test_theme_demo_course_document_is_template_review_not_imported(): void
    {
        $tenant = $this->makeTenant('demo-course-tenant');
        $records = [$this->documentRecord('legacy-course-1', 'https://aisi.edu.ge/courses/learn-php-programming-from-scratch/', 'Related Courses', 'Lorem Ipsum is simply dummy text.')];

        $report = (new ImportContentRecords)->run($tenant, $records, ['pages', 'posts', 'documents', 'teachers'], [], (string) Str::uuid(), commit: true);

        $this->assertSame(['template_review'], array_column($report, 'action'));
        $this->assertSame(0, Page::query()->where('tenant_id', $tenant->id)->count());
        $this->assertSame(
            ContentImportRecord::STATUS_TEMPLATE_REVIEW,
            ContentImportRecord::query()->where('tenant_id', $tenant->id)->sole()->review_status,
        );
    }

    public function test_theme_demo_event_document_is_template_review_not_imported(): void
    {
        $tenant = $this->makeTenant('demo-event-tenant');
        $records = [$this->documentRecord('legacy-event-1', 'https://aisi.edu.ge/event/basis-international-award-night/', 'EVENT INFO :', 'Melbourne, Australia. Lorem Ipsum dummy text.')];

        $report = (new ImportContentRecords)->run($tenant, $records, ['pages', 'posts', 'documents', 'teachers'], [], (string) Str::uuid(), commit: true);

        $this->assertSame(['template_review'], array_column($report, 'action'));
        $this->assertSame(0, Page::query()->where('tenant_id', $tenant->id)->count());
    }

    public function test_real_aisi_event_document_is_imported_despite_event_url_pattern(): void
    {
        $tenant = $this->makeTenant('real-event-tenant');
        $records = [$this->documentRecord('legacy-ca0b723d7c8b', 'https://aisi.edu.ge/event/კერძო-სკოლა-აისი-და-ism-university-of-management-and-e/', 'EVENT INFO :', 'კერძო სკოლა "აისი" და ISM University-ის ვებინარი.')];

        $report = (new ImportContentRecords)->run($tenant, $records, ['pages', 'posts', 'documents', 'teachers'], [], (string) Str::uuid(), commit: true);

        $this->assertSame(['created'], array_column($report, 'action'));
        $page = Page::query()->where('tenant_id', $tenant->id)->sole();
        $this->assertSame('კერძო სკოლა "აისი" და ISM University-ის ვებინარი.', $page->blocks[0]['body']);
    }

    public function test_research_archive_document_is_imported_as_a_draft_page(): void
    {
        $tenant = $this->makeTenant('research-tenant');
        $records = [$this->documentRecord('legacy-research-i', 'https://aisi.edu.ge/research/i/', 'საარქივო გვერდი', 'ქართული, მათემატიკა, ინგლისური.')];

        $report = (new ImportContentRecords)->run($tenant, $records, ['pages', 'posts', 'documents', 'teachers'], [], (string) Str::uuid(), commit: true);

        $this->assertSame(['created'], array_column($report, 'action'));
        $this->assertSame(1, Page::query()->where('tenant_id', $tenant->id)->count());
    }

    public function test_teacher_record_is_imported_as_a_generic_draft_page(): void
    {
        $tenant = $this->makeTenant('teacher-tenant');
        $records = [$this->teacherRecord('legacy-teacher-1', 'თორნიკე მუშკუდიანი', 'დაწყებითის პედაგოგი')];

        $report = (new ImportContentRecords)->run($tenant, $records, ['pages', 'posts', 'documents', 'teachers'], [], (string) Str::uuid(), commit: true);

        $this->assertSame(['created'], array_column($report, 'action'));
        $this->assertStringContainsString('no dedicated staff-profile model exists yet', $report[0]['reason']);
        $page = Page::query()->where('tenant_id', $tenant->id)->sole();
        $this->assertSame(Page::STATUS_DRAFT, $page->status);
        $this->assertStringContainsString('თორნიკე მუშკუდიანი', $page->blocks[0]['body']);
    }

    public function test_second_commit_run_over_template_review_document_does_not_duplicate_or_crash(): void
    {
        $tenant = $this->makeTenant('template-review-rerun-tenant');
        $records = [$this->documentRecord('legacy-course-1', 'https://aisi.edu.ge/courses/learn-php-programming-from-scratch/', 'Related Courses')];

        (new ImportContentRecords)->run($tenant, $records, ['pages', 'posts', 'documents', 'teachers'], [], (string) Str::uuid(), commit: true);
        $report = (new ImportContentRecords)->run($tenant, $records, ['pages', 'posts', 'documents', 'teachers'], [], (string) Str::uuid(), commit: true);

        $this->assertSame(['blocked'], array_column($report, 'action'));
        $this->assertSame(1, ContentImportRecord::query()->where('tenant_id', $tenant->id)->count());
    }

    public function test_post_with_unresolvable_featured_media_reports_an_unresolved_hint(): void
    {
        $tenant = $this->makeTenant('media-hint-tenant');
        // A featured_media id guaranteed not to exist in any real/local
        // media dump, so this assertion holds regardless of whether the
        // gitignored content-migration/raw/ working files happen to be
        // present on the machine running the test.
        $records = [$this->postRecord('wp-posts-1', 201, featuredMedia: 999999999)];

        $report = (new ImportContentRecords)->run($tenant, $records, ['pages', 'posts', 'documents', 'teachers'], [], (string) Str::uuid(), commit: true);

        $this->assertArrayHasKey('media_hint', $report[0]);
        $this->assertFalse($report[0]['media_hint']['resolved']);
        $this->assertSame(999999999, $report[0]['media_hint']['featured_media_id']);
    }

    public function test_post_without_featured_media_has_no_media_hint(): void
    {
        $tenant = $this->makeTenant('no-media-hint-tenant');
        $records = [$this->postRecord('wp-posts-1', 201)];

        $report = (new ImportContentRecords)->run($tenant, $records, ['pages', 'posts', 'documents', 'teachers'], [], (string) Str::uuid(), commit: true);

        $this->assertArrayNotHasKey('media_hint', $report[0]);
    }

    public function test_type_not_in_only_is_skipped_out_of_scope(): void
    {
        $tenant = $this->makeTenant('scope-tenant');
        $records = [
            $this->pageRecord('wp-pages-1', 101),
            ['key' => 'legacy-1', 'source_id' => null, 'type' => 'documents', 'source_url' => 'https://aisi.edu.ge/x/', 'title' => 'X', 'excerpt' => null, 'original_clean_text' => 'x', 'body' => 'x', 'target_path' => '/documents/x'],
        ];

        $report = (new ImportContentRecords)->run($tenant, $records, ['pages', 'posts'], [], (string) Str::uuid(), commit: true);

        $this->assertSame(['created', 'skipped_out_of_scope'], array_column($report, 'action'));
        $this->assertSame(0, ContentImportRecord::query()->where('tenant_id', $tenant->id)->where('source_type', 'documents')->count());
    }

    public function test_import_preserves_the_real_historical_published_at_even_while_draft(): void
    {
        $tenant = $this->makeTenant('history-date-tenant');
        $records = [$this->postRecord('wp-posts-1', 201, publishedAt: '2021-03-15T10:00:00')];

        (new ImportContentRecords)->run($tenant, $records, ['posts'], [], (string) Str::uuid(), commit: true);

        $post = Post::query()->where('tenant_id', $tenant->id)->sole();
        $this->assertSame(Post::STATUS_DRAFT, $post->status);
        $this->assertSame('2021-03-15', $post->published_at?->toDateString());
    }

    public function test_publishing_an_imported_post_uses_the_real_date_not_today(): void
    {
        $tenant = $this->makeTenant('publish-date-tenant');
        $records = [$this->postRecord('wp-posts-1', 201, publishedAt: '2020-06-01T09:00:00')];
        (new ImportContentRecords)->run($tenant, $records, ['posts'], [], (string) Str::uuid(), commit: true);
        $post = Post::query()->where('tenant_id', $tenant->id)->sole();

        app(ChangePostStatus::class)->handle($post, true, User::factory()->create());

        $this->assertSame('2020-06-01', $post->fresh()->published_at?->toDateString());
    }

    public function test_rerunning_import_backfills_a_previously_null_published_at(): void
    {
        $tenant = $this->makeTenant('backfill-date-tenant');
        $records = [$this->postRecord('wp-posts-1', 201)];
        (new ImportContentRecords)->run($tenant, $records, ['posts'], [], (string) Str::uuid(), commit: true);
        $post = Post::query()->where('tenant_id', $tenant->id)->sole();
        $this->assertNull($post->published_at);

        $recordsWithDate = [$this->postRecord('wp-posts-1', 201, publishedAt: '2019-09-01T12:00:00')];
        (new ImportContentRecords)->run($tenant, $recordsWithDate, ['posts'], [], (string) Str::uuid(), commit: true);

        $this->assertSame('2019-09-01', $post->fresh()->published_at?->toDateString());
    }

    public function test_rerunning_import_corrects_a_post_wrongly_dated_by_a_bulk_publish(): void
    {
        $tenant = $this->makeTenant('correct-date-tenant');
        $records = [$this->postRecord('wp-posts-1', 201)];
        (new ImportContentRecords)->run($tenant, $records, ['posts'], [], (string) Str::uuid(), commit: true);
        $post = Post::query()->where('tenant_id', $tenant->id)->sole();

        // Simulates the real incident: publishing before the real date was
        // known stamps today's date as the best available fallback.
        app(ChangePostStatus::class)->handle($post, true, User::factory()->create());
        $this->assertTrue($post->fresh()->published_at->isToday());

        $recordsWithDate = [$this->postRecord('wp-posts-1', 201, publishedAt: '2018-01-10T08:00:00')];
        (new ImportContentRecords)->run($tenant, $recordsWithDate, ['posts'], [], (string) Str::uuid(), commit: true);

        $this->assertSame('2018-01-10', $post->fresh()->published_at?->toDateString());
        $this->assertSame(Post::STATUS_PUBLISHED, $post->fresh()->status);
    }
}
