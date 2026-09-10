<?php

namespace Tests\Feature\ContentImport;

use App\Domain\Content\Actions\ImportContentRecords;
use App\Domain\Content\Models\ContentImportRecord;
use App\Domain\Content\Models\Page;
use App\Domain\Content\Models\Post;
use App\Domain\Tenancy\Models\Tenant;
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
    private function postRecord(string $key, int $sourceId, string $title = 'სიახლე', string $body = 'ორიგინალი სიახლის ტექსტი.'): array
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
}
