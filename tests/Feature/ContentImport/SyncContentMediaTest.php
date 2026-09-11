<?php

namespace Tests\Feature\ContentImport;

use App\Domain\Content\Actions\ImportContentRecords;
use App\Domain\Content\Actions\SyncContentMedia;
use App\Domain\Content\Models\ContentImportRecord;
use App\Domain\Content\Models\Post;
use App\Domain\Content\Support\LegacyMediaManifest;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * docs/08-content-migration.md §6: {@see SyncContentMedia} is the follow-up
 * to {@see ImportContentRecords} that actually copies a resolved
 * featured-image asset's bytes into tenant storage and writes
 * Post.cover_image_path — using a temp fixture tree instead of the real
 * (gitignored, not-committed) content-migration/assets + raw/api dumps, so
 * this suite never depends on that locally-cached working data existing.
 */
class SyncContentMediaTest extends TestCase
{
    use RefreshDatabase;

    private string $fixtureRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtureRoot = sys_get_temp_dir().'/aisi-sync-media-test-'.Str::random(8);
        mkdir($this->fixtureRoot, recursive: true);
        mkdir($this->fixtureRoot.'/assets', recursive: true);

        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->fixtureRoot);

        parent::tearDown();
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }

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
     * Writes a fixture asset file + the media dump + asset-manifest.json
     * that resolve WordPress media id 555 -> that file, and returns the
     * configured SyncContentMedia action.
     */
    private function actionWithFixture(string $assetBytes = 'fake-jpeg-bytes'): SyncContentMedia
    {
        $assetFile = $this->fixtureRoot.'/assets/photo.jpg';
        file_put_contents($assetFile, $assetBytes);
        $sha256 = hash('sha256', $assetBytes);

        file_put_contents($this->fixtureRoot.'/media-1.json', json_encode([
            ['id' => 555, 'source_url' => 'https://aisi.edu.ge/wp-content/uploads/2020/01/photo.jpg'],
        ]));

        file_put_contents($this->fixtureRoot.'/asset-manifest.json', json_encode([
            [
                'source_url' => 'https://aisi.edu.ge/wp-content/uploads/2020/01/photo.jpg',
                'local_path' => 'assets/photo.jpg',
                'bytes' => strlen($assetBytes),
                'sha256' => $sha256,
                'rights_status' => 'school_confirmation_required',
                'download_status' => 'saved',
            ],
        ]));

        file_put_contents($this->fixtureRoot.'/cms-import.json', json_encode([
            'schema_version' => 1,
            'tenant_required' => true,
            'default_status' => 'draft',
            'records' => [
                [
                    'key' => 'wp-posts-1',
                    'source_id' => 201,
                    'type' => 'posts',
                    'source_url' => 'https://aisi.edu.ge/news/201/',
                    'title' => 'სიახლე',
                    'excerpt' => 'excerpt',
                    'original_clean_text' => 'ტექსტი.',
                    'body' => 'ტექსტი.',
                    'target_path' => '/posts/201',
                    'featured_media' => 555,
                ],
                // Referenced by test_unresolved_media_id_is_reported: a
                // featured_media id guaranteed to never appear in the fixture
                // media dump above, so it resolves to "unresolved" rather
                // than silently being skipped as "no featured_media at all".
                [
                    'key' => 'wp-posts-2',
                    'source_id' => 202,
                    'type' => 'posts',
                    'source_url' => 'https://aisi.edu.ge/news/202/',
                    'title' => 'სიახლე 2',
                    'excerpt' => 'excerpt',
                    'original_clean_text' => 'ტექსტი.',
                    'body' => 'ტექსტი.',
                    'target_path' => '/posts/202',
                    'featured_media' => 999999999,
                ],
                [
                    'key' => 'wp-posts-3',
                    'source_id' => 203,
                    'type' => 'posts',
                    'source_url' => 'https://aisi.edu.ge/news/203/',
                    'title' => 'სიახლე 3',
                    'excerpt' => 'excerpt',
                    'original_clean_text' => 'ტექსტი.',
                    'body' => 'ტექსტი.',
                    'target_path' => '/posts/203',
                    'featured_media' => 555,
                ],
            ],
        ]));

        $manifest = new LegacyMediaManifest(
            mediaDumpGlob: $this->fixtureRoot.'/media-*.json',
            manifestPath: $this->fixtureRoot.'/asset-manifest.json',
        );

        return new SyncContentMedia(
            mediaManifest: $manifest,
            assetsRoot: $this->fixtureRoot,
            cmsImportPath: $this->fixtureRoot.'/cms-import.json',
        );
    }

    private function importOnePost(Tenant $tenant): void
    {
        $records = [[
            'key' => 'wp-posts-1',
            'source_id' => 201,
            'type' => 'posts',
            'source_url' => 'https://aisi.edu.ge/news/201/',
            'title' => 'სიახლე',
            'excerpt' => 'excerpt',
            'original_clean_text' => 'ტექსტი.',
            'body' => 'ტექსტი.',
            'target_path' => '/posts/201',
            'featured_media' => 555,
        ]];

        (new ImportContentRecords)->run($tenant, $records, ['pages', 'posts'], [], (string) Str::uuid(), commit: true);
    }

    public function test_dry_run_copies_nothing(): void
    {
        $tenant = $this->makeTenant('sync-dry-tenant');
        $this->importOnePost($tenant);
        $action = $this->actionWithFixture();

        $report = $action->run($tenant, commit: false);

        $this->assertSame(['copy'], array_column($report, 'action'));
        Storage::disk('public')->assertMissing('content-migration/photo.jpg');
        $post = Post::query()->where('tenant_id', $tenant->id)->sole();
        $this->assertNull($post->cover_image_path);
    }

    public function test_commit_copies_bytes_and_sets_cover_image_path(): void
    {
        $tenant = $this->makeTenant('sync-commit-tenant');
        $this->importOnePost($tenant);
        $action = $this->actionWithFixture('real-bytes-of-a-photo');

        $report = $action->run($tenant, commit: true);

        $this->assertSame(['copied'], array_column($report, 'action'));
        Storage::disk('public')->assertExists('content-migration/photo.jpg');
        $this->assertSame('real-bytes-of-a-photo', Storage::disk('public')->get('content-migration/photo.jpg'));

        $post = Post::query()->where('tenant_id', $tenant->id)->sole();
        $this->assertSame('content-migration/photo.jpg', $post->cover_image_path);
    }

    public function test_second_commit_run_is_idempotent_no_op(): void
    {
        $tenant = $this->makeTenant('sync-idempotent-tenant');
        $this->importOnePost($tenant);
        $action = $this->actionWithFixture('real-bytes-of-a-photo');

        $action->run($tenant, commit: true);
        $report = $action->run($tenant, commit: true);

        $this->assertSame(['skip_unchanged'], array_column($report, 'action'));
        $this->assertSame(1, Post::query()->where('tenant_id', $tenant->id)->count());
    }

    public function test_missing_local_asset_file_is_reported_not_fabricated(): void
    {
        $tenant = $this->makeTenant('sync-missing-tenant');
        $this->importOnePost($tenant);
        $action = $this->actionWithFixture();

        // Delete the fixture asset AFTER wiring the manifest to it, so the
        // manifest resolves but the underlying bytes genuinely don't exist —
        // exactly the state this whole ticket exists to detect and never
        // silently paper over.
        unlink($this->fixtureRoot.'/assets/photo.jpg');

        $report = $action->run($tenant, commit: true);

        $this->assertSame(['missing_source_file'], array_column($report, 'action'));
        Storage::disk('public')->assertMissing('content-migration/photo.jpg');
        $post = Post::query()->where('tenant_id', $tenant->id)->sole();
        $this->assertNull($post->cover_image_path);
    }

    public function test_checksum_mismatch_is_reported_not_synced(): void
    {
        $tenant = $this->makeTenant('sync-checksum-tenant');
        $this->importOnePost($tenant);
        $action = $this->actionWithFixture('original-bytes');

        // Simulate the cached file having changed/corrupted since the
        // manifest recorded its checksum.
        file_put_contents($this->fixtureRoot.'/assets/photo.jpg', 'tampered-bytes');

        $report = $action->run($tenant, commit: true);

        $this->assertSame(['checksum_mismatch'], array_column($report, 'action'));
        Storage::disk('public')->assertMissing('content-migration/photo.jpg');
    }

    public function test_existing_manual_cover_image_path_is_never_overwritten(): void
    {
        $tenant = $this->makeTenant('sync-conflict-tenant');
        $this->importOnePost($tenant);
        $action = $this->actionWithFixture();

        $post = Post::query()->where('tenant_id', $tenant->id)->sole();
        $post->cover_image_path = 'manually/uploaded.jpg';
        $post->save();

        $report = $action->run($tenant, commit: true);

        $this->assertSame(['conflict'], array_column($report, 'action'));
        $post->refresh();
        $this->assertSame('manually/uploaded.jpg', $post->cover_image_path);
        Storage::disk('public')->assertMissing('content-migration/photo.jpg');
    }

    public function test_unresolved_media_id_is_reported(): void
    {
        $tenant = $this->makeTenant('sync-unresolved-tenant');
        (new ImportContentRecords)->run($tenant, [[
            'key' => 'wp-posts-2',
            'source_id' => 202,
            'type' => 'posts',
            'source_url' => 'https://aisi.edu.ge/news/202/',
            'title' => 'სიახლე 2',
            'excerpt' => 'excerpt',
            'original_clean_text' => 'ტექსტი.',
            'body' => 'ტექსტი.',
            'target_path' => '/posts/202',
            'featured_media' => 999999999,
        ]], ['pages', 'posts'], [], (string) Str::uuid(), commit: true);

        $action = $this->actionWithFixture();
        $report = $action->run($tenant, commit: true);

        $this->assertSame(['unresolved'], array_column($report, 'action'));
    }

    public function test_tenant_a_sync_never_touches_tenant_b_post(): void
    {
        $tenantA = $this->makeTenant('sync-tenant-a');
        $tenantB = $this->makeTenant('sync-tenant-b');
        $this->importOnePost($tenantA);
        $this->importOnePost($tenantB);
        $action = $this->actionWithFixture('real-bytes-of-a-photo');

        $action->run($tenantA, commit: true);

        $postA = Post::query()->where('tenant_id', $tenantA->id)->sole();
        $postB = Post::query()->where('tenant_id', $tenantB->id)->sole();
        $this->assertSame('content-migration/photo.jpg', $postA->cover_image_path);
        $this->assertNull($postB->cover_image_path);
    }

    public function test_limit_option_bounds_how_many_records_are_processed(): void
    {
        $tenant = $this->makeTenant('sync-limit-tenant');
        $this->importOnePost($tenant);
        (new ImportContentRecords)->run($tenant, [[
            'key' => 'wp-posts-3',
            'source_id' => 203,
            'type' => 'posts',
            'source_url' => 'https://aisi.edu.ge/news/203/',
            'title' => 'სიახლე 3',
            'excerpt' => 'excerpt',
            'original_clean_text' => 'ტექსტი.',
            'body' => 'ტექსტი.',
            'target_path' => '/posts/203',
            'featured_media' => 555,
        ]], ['pages', 'posts'], [], (string) Str::uuid(), commit: true);

        $action = $this->actionWithFixture();
        $report = $action->run($tenant, commit: false, limit: 1);

        $this->assertCount(1, $report);
        $this->assertSame(2, ContentImportRecord::query()->where('tenant_id', $tenant->id)->where('source_type', 'posts')->count());
    }
}
