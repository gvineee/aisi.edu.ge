<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Models\ContentImportRecord;
use App\Domain\Content\Models\LegacyRedirect;
use App\Domain\Content\Models\Page;
use App\Domain\Content\Models\Post;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CLAUDE.md "მიგრაცია და გაშვება": old WordPress URLs must keep working via
 * 301s, scoped to the school whose domain was requested, and only towards
 * content that is actually published.
 */
class LegacyRedirectsTest extends TestCase
{
    use RefreshDatabase;

    private const LEGACY_SLUG = 'კერძო-სკოლა-აისი-და-ism';

    private function localTenant(): Tenant
    {
        return TenantDomain::query()->where('domain', 'localhost')->firstOrFail()->tenant;
    }

    private function importedPost(Tenant $tenant, string $slug, string $status, string $sourceUrl): Post
    {
        $post = $tenant->posts()->create([
            'slug' => $slug, 'locale' => 'ka', 'title' => 'სიახლე', 'body' => 'ტექსტი',
            'status' => $status, 'published_at' => $status === Post::STATUS_PUBLISHED ? now() : null,
        ]);

        $record = new ContentImportRecord([
            'source_key' => 'wp:posts:'.$post->id, 'source_type' => 'posts', 'source_url' => $sourceUrl,
            'importable_type' => Post::class, 'importable_id' => $post->id,
            'import_batch_id' => (string) Str::uuid(), 'review_status' => ContentImportRecord::STATUS_IMPORTED,
        ]);
        $record->tenant_id = $tenant->id;
        $record->save();

        return $post;
    }

    public function test_normalization_collapses_encodings_case_trailing_slash_and_language_copies(): void
    {
        $expected = '/'.self::LEGACY_SLUG;

        foreach ([
            'https://aisi.edu.ge/'.rawurlencode(self::LEGACY_SLUG).'/',
            '/'.strtoupper(rawurlencode(self::LEGACY_SLUG)),
            '/en/'.rawurlencode(self::LEGACY_SLUG).'/',
            '/ru/'.self::LEGACY_SLUG.'?utm_source=x',
        ] as $variant) {
            $this->assertSame($expected, LegacyRedirect::normalizePath($variant), $variant);
        }

        $this->assertSame('/', LegacyRedirect::normalizePath('https://aisi.edu.ge/en/'));
    }

    public function test_sync_is_dry_run_by_default_and_only_maps_published_imports(): void
    {
        $tenant = $this->localTenant();
        $this->importedPost($tenant, 'ism-partnership', Post::STATUS_PUBLISHED, 'https://aisi.edu.ge/'.rawurlencode(self::LEGACY_SLUG).'/');
        $this->importedPost($tenant, 'still-draft', Post::STATUS_DRAFT, 'https://aisi.edu.ge/draft-legacy/');

        $this->artisan('content:sync-redirects', ['tenant' => $tenant->slug])->assertSuccessful();
        $this->assertSame(0, LegacyRedirect::query()->count());

        $this->artisan('content:sync-redirects', ['tenant' => $tenant->slug, '--commit' => true])->assertSuccessful();
        $this->artisan('content:sync-redirects', ['tenant' => $tenant->slug, '--commit' => true])->assertSuccessful();

        $this->assertSame(1, LegacyRedirect::query()->count());
        $redirect = LegacyRedirect::query()->firstOrFail();
        $this->assertSame('/'.self::LEGACY_SLUG, $redirect->source_path);
        $this->assertSame('/news/ism-partnership', $redirect->target_path);
        $this->assertSame(LegacyRedirect::ORIGIN_IMPORT, $redirect->origin);
    }

    public function test_sync_never_overwrites_a_manual_redirect(): void
    {
        $tenant = $this->localTenant();
        $this->importedPost($tenant, 'ism-partnership', Post::STATUS_PUBLISHED, 'https://aisi.edu.ge/old-post/');

        $manual = new LegacyRedirect([
            'source_path' => '/old-post', 'source_hash' => LegacyRedirect::hashPath('/old-post'),
            'target_path' => '/about', 'status_code' => 301, 'origin' => LegacyRedirect::ORIGIN_MANUAL,
        ]);
        $manual->tenant_id = $tenant->id;
        $manual->save();

        $this->artisan('content:sync-redirects', ['tenant' => $tenant->slug, '--commit' => true])->assertSuccessful();

        $this->assertSame('/about', $manual->fresh()?->target_path);
    }

    public function test_legacy_urls_in_every_spelling_301_to_the_new_page_and_count_hits(): void
    {
        $tenant = $this->localTenant();
        $this->importedPost($tenant, 'ism-partnership', Post::STATUS_PUBLISHED, 'https://aisi.edu.ge/'.rawurlencode(self::LEGACY_SLUG).'/');
        $this->artisan('content:sync-redirects', ['tenant' => $tenant->slug, '--commit' => true]);

        foreach ([
            '/'.rawurlencode(self::LEGACY_SLUG).'/',
            '/'.strtoupper(rawurlencode(self::LEGACY_SLUG)),
            '/en/'.rawurlencode(self::LEGACY_SLUG).'/',
        ] as $legacyUrl) {
            $this->get($legacyUrl)->assertStatus(301)->assertRedirect('/news/ism-partnership');
        }

        $this->get('/news/ism-partnership')->assertOk();
        $this->assertSame(3, LegacyRedirect::query()->firstOrFail()->hits);
    }

    public function test_redirects_never_shadow_a_live_page_or_apply_to_writes_or_unknown_paths(): void
    {
        $tenant = $this->localTenant();
        $tenant->pages()->create([
            'slug' => 'about', 'locale' => 'ka', 'title' => 'ჩვენ შესახებ',
            'blocks' => [], 'status' => Page::STATUS_PUBLISHED, 'published_at' => now(),
        ]);

        foreach (['/about' => '/contact', '/gone-post' => '/about'] as $from => $to) {
            $redirect = new LegacyRedirect([
                'source_path' => $from, 'source_hash' => LegacyRedirect::hashPath($from),
                'target_path' => $to, 'status_code' => 301, 'origin' => LegacyRedirect::ORIGIN_MANUAL,
            ]);
            $redirect->tenant_id = $tenant->id;
            $redirect->save();
        }

        $this->get('/about')->assertOk();
        $this->post('/gone-post')->assertStatus(405);
        $this->get('/wp-login.php')->assertNotFound();
        $this->get('/gone-post')->assertRedirect('/about');
    }

    public function test_manual_redirect_command_rejects_off_site_targets_and_can_remove(): void
    {
        $tenant = $this->localTenant();

        $this->artisan('content:redirect', ['tenant' => $tenant->slug, 'from' => '/online-library/', 'to' => 'https://evil.example'])->assertFailed();
        $this->artisan('content:redirect', ['tenant' => $tenant->slug, 'from' => '/online-library/', 'to' => '//evil.example'])->assertFailed();
        $this->assertSame(0, LegacyRedirect::query()->count());

        $this->artisan('content:redirect', ['tenant' => $tenant->slug, 'from' => 'https://aisi.edu.ge/online-library/', 'to' => '/library'])->assertSuccessful();
        $this->get('/online-library/')->assertRedirect('/library');

        $this->artisan('content:redirect', ['tenant' => $tenant->slug, 'from' => '/online-library', '--remove' => true])->assertSuccessful();
        $this->assertSame(0, LegacyRedirect::query()->count());
    }

    public function test_another_schools_redirects_are_never_applied(): void
    {
        $other = Tenant::create([
            'slug' => 'other', 'name' => 'Other', 'locale' => 'ka', 'timezone' => 'UTC', 'is_active' => true,
        ]);
        TenantDomain::create(['tenant_id' => $other->id, 'domain' => 'other.test', 'is_primary' => true]);

        $redirect = new LegacyRedirect([
            'source_path' => '/old-post', 'source_hash' => LegacyRedirect::hashPath('/old-post'),
            'target_path' => '/news/other-school', 'status_code' => 301, 'origin' => LegacyRedirect::ORIGIN_MANUAL,
        ]);
        $redirect->tenant_id = $other->id;
        $redirect->save();

        $this->get('/old-post')->assertNotFound();
        $this->get('http://other.test/old-post')->assertRedirect('/news/other-school');
    }

    public function test_public_pages_name_the_primary_domain_as_canonical_and_portal_pages_do_not(): void
    {
        $tenant = $this->localTenant();
        $tenant->pages()->create([
            'slug' => 'about', 'locale' => 'ka', 'title' => 'ჩვენ შესახებ',
            'blocks' => [], 'status' => Page::STATUS_PUBLISHED, 'published_at' => now(),
        ]);
        TenantDomain::create(['tenant_id' => $tenant->id, 'domain' => 'www.localhost', 'is_primary' => false]);

        $this->get('http://www.localhost/about?utm_source=x')
            ->assertOk()
            ->assertSee('<link rel="canonical" href="http://localhost/about">', false)
            ->assertSee('<link rel="alternate" hreflang="ka" href="http://localhost/about">', false)
            ->assertSee('hreflang="x-default"', false);

        $this->get('/login')->assertOk()->assertDontSee('rel="canonical"', false);
    }
}
