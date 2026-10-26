<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Models\ContentMediaLink;
use App\Domain\Content\Models\Page;
use App\Domain\Tenancy\Models\TenantDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A resized-only image is swapped for its original from the old uploads
 * folder — only on --commit, only for a real image with the exact name.
 */
class UpgradeVariantMediaTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_variant_is_replaced_by_its_original_only_on_commit(): void
    {
        Storage::fake('public');
        $tenant = TenantDomain::query()->where('domain', 'localhost')->firstOrFail()->tenant;
        $page = $tenant->pages()->create(['slug' => 'gallery', 'locale' => 'ka', 'title' => 'გალერეა', 'blocks' => [], 'status' => Page::STATUS_PUBLISHED, 'published_at' => now()]);
        $uploads = sys_get_temp_dir().'/uploads-'.uniqid();
        mkdir("{$uploads}/2026/03", 0777, true);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        file_put_contents("{$uploads}/2026/03/IMG_1.png", $png);
        file_put_contents("{$uploads}/2026/03/NOTIMG.png", 'not an image');

        $link = fn (string $name, int $position) => tap(new ContentMediaLink([
            'linkable_type' => Page::class, 'linkable_id' => $page->id, 'role' => 'image', 'position' => $position,
            'disk_path' => "content-migration/body/x-{$name}", 'mime' => 'image/png', 'bytes' => 10, 'sha256' => str_repeat('0', 64),
            'source_url' => "https://old.test/wp-content/uploads/2026/03/{$name}", 'resolution' => 'variant_only',
        ]), function (ContentMediaLink $l) use ($tenant) {
            $l->tenant_id = $tenant->id;
            $l->save();
        });
        $good = $link('IMG_1-360x370.png', 0);
        $fake = $link('NOTIMG-360x370.png', 1);

        Artisan::call('content:upgrade-variant-media', ['uploads' => $uploads, '--tenant' => $tenant->slug]);
        $this->assertSame('variant_only', $good->fresh()->resolution, 'preview changes nothing');

        Artisan::call('content:upgrade-variant-media', ['uploads' => $uploads, '--tenant' => $tenant->slug, '--commit' => true]);
        $this->assertSame(['original', hash('sha256', $png)], [$good->fresh()->resolution, $good->fresh()->sha256]);
        $this->assertStringEndsWith('/IMG_1.png', $good->fresh()->source_url);
        Storage::disk('public')->assertExists($good->fresh()->disk_path);
        $this->assertSame('variant_only', $fake->fresh()->resolution, 'a non-image never replaces anything');
    }
}
