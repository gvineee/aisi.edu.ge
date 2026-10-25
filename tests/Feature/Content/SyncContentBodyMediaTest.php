<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Actions\SyncContentBodyMedia;
use App\Domain\Content\Models\ContentImportRecord;
use App\Domain\Content\Models\ContentMediaLink;
use App\Domain\Content\Models\Post;
use App\Domain\Content\Support\LegacyMediaManifest;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Body media from a synthetic legacy collection: originals over resized
 * variants, gallery order/alt/caption kept, one attachment per file with
 * its real label, emoji / empty <img/> / HTML-as-JPEG never linked, text
 * untouched, reruns idempotent, other link sets never overwritten.
 */
class SyncContentBodyMediaTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private Tenant $tenant;

    private Post $post;

    private const BASE = 'https://legacy.test/wp-content/uploads/2026/02/';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->tenant = TenantDomain::query()->where('domain', 'localhost')->firstOrFail()->tenant;
        $this->root = sys_get_temp_dir().'/legacy-'.Str::random(8);
        File::ensureDirectoryExists($this->root.'/raw/api');
        File::ensureDirectoryExists($this->root.'/assets');

        // A 1×1 PNG, a minimal PDF and an HTML error page saved as .jpg.
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
        $files = [
            'a.png' => $png,
            'b.png' => $png."\0",
            'report.pdf' => "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF",
            'broken.jpg' => '<!doctype html><html><body>Not found</body></html>',
        ];
        $manifest = [];
        foreach ($files as $name => $bytes) {
            file_put_contents($this->root.'/assets/'.$name, $bytes);
            $manifest[] = ['source_url' => self::BASE.$name, 'local_path' => 'assets/'.$name, 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'rights_status' => 'school_confirmation_required', 'download_status' => 'saved'];
        }
        file_put_contents($this->root.'/asset-manifest.json', json_encode($manifest));
        // Media 77 is the original behind the resized b-768x1024.png.
        file_put_contents($this->root.'/raw/api/media-1.json', json_encode([['id' => 77, 'source_url' => self::BASE.'b.png']]));

        $html = '<p>ტექსტი <img src="https://static.xx.fbcdn.net/images/emoji.php/v9/x.png" alt="👏"></p>'
            .'<figure class="wp-block-gallery has-nested-images">'
            .'<figure class="wp-block-image"><img src="'.self::BASE.'a.png" alt="პირველი"><figcaption>ეზო</figcaption></figure>'
            .'<figure class="wp-block-image"><img data-id="77" src="'.self::BASE.'b-768x1024.png" alt="მეორე"></figure>'
            .'<figure class="wp-block-image"><img/></figure>'
            .'</figure>'
            .'<img src="'.self::BASE.'broken.jpg" alt="">'
            .'<p><a href="'.self::BASE.'report.pdf">ფინანსური ანგარიში</a> <a href="'.self::BASE.'report.pdf">Download</a></p>';
        file_put_contents($this->root.'/raw/api/posts-1.json', json_encode([['id' => 2443, 'content' => ['rendered' => $html]]]));

        $this->post = $this->tenant->posts()->create([
            'slug' => 'import-wp-posts-2443', 'locale' => 'ka', 'title' => 'ჩემპიონატი', 'body' => 'ორიგინალი ტექსტი',
            'status' => Post::STATUS_PUBLISHED, 'published_at' => '2026-01-11 06:29:00',
        ]);
        $record = new ContentImportRecord([
            'source_key' => 'wp-posts-2443', 'source_id' => 2443, 'source_type' => 'posts', 'source_url' => 'https://legacy.test/?p=2443',
            'importable_type' => Post::class, 'importable_id' => $this->post->id, 'import_batch_id' => (string) Str::uuid(), 'review_status' => 'imported',
        ]);
        $record->tenant_id = $this->tenant->id;
        $record->save();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function sync(bool $commit): array
    {
        $action = new SyncContentBodyMedia(new LegacyMediaManifest($this->root.'/raw/api/media-*.json', $this->root.'/asset-manifest.json'), $this->root);

        return $action->run($this->tenant, $commit)[0];
    }

    public function test_preview_then_commit_links_originals_in_order_and_rejects_bad_files(): void
    {
        $preview = $this->sync(false);
        $this->assertSame('would_link', $preview['disposition']);
        $this->assertSame(0, ContentMediaLink::query()->count());

        $row = $this->sync(true);
        $this->assertSame('linked', $row['disposition']);
        $this->assertSame(1, $row['emoji_skipped']);
        $this->assertCount(2, $row['issues'], 'empty <img/> and the HTML-as-JPEG');

        $images = ContentMediaLink::query()->where('role', 'image')->orderBy('position')->get();
        $this->assertSame([['პირველი', 'ეზო', 'gallery-1'], ['მეორე', null, 'gallery-1']], $images->map(fn ($i) => [$i->alt, $i->caption, $i->group_key])->all());
        $this->assertSame(self::BASE.'b.png', $images[1]->source_url, 'the original, not the 768px variant');
        $this->assertSame(['original', 'original'], $images->pluck('resolution')->all());

        $file = ContentMediaLink::query()->where('role', 'attachment')->sole();
        $this->assertSame(['ფინანსური ანგარიში', 'application/pdf'], [$file->label, $file->mime]);
        Storage::disk('public')->assertExists($file->disk_path);

        $this->assertSame('ორიგინალი ტექსტი', $this->post->fresh()->body);
        $this->assertSame('unchanged', $this->sync(true)['disposition']);

        $this->get('/news/import-wp-posts-2443')->assertOk()
            ->assertInertia(fn ($page) => $page->has('media.images', 2)->where('media.attachments.0.label', 'ფინანსური ანგარიში'))
            ->assertSee('alt="პირველი"', false);
    }

    public function test_an_existing_different_set_of_links_is_not_overwritten(): void
    {
        $other = new ContentMediaLink([
            'linkable_type' => Post::class, 'linkable_id' => $this->post->id, 'role' => 'image', 'position' => 1,
            'disk_path' => 'manual.png', 'mime' => 'image/png', 'bytes' => 1, 'sha256' => str_repeat('0', 64),
            'source_url' => 'manual', 'resolution' => 'original',
        ]);
        $other->tenant_id = $this->tenant->id;
        $other->save();

        $this->assertSame('conflict', $this->sync(true)['disposition']);
        $this->assertSame(1, ContentMediaLink::query()->count());
    }
}
