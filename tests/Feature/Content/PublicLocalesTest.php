<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Models\Page;
use App\Domain\Content\Models\Post;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public site in other languages: a language exists only once a page
 * is published in it; translations live under /{locale} with the same
 * slug; the switcher and hreflang point only at real translations; and
 * each language's news list holds only its own posts.
 */
class PublicLocalesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = TenantDomain::query()->where('domain', 'localhost')->firstOrFail()->tenant;
        $this->page('history', 'ka', 'სკოლის ისტორია');
    }

    private function page(string $slug, string $locale, string $title, string $status = Page::STATUS_PUBLISHED): Page
    {
        return $this->tenant->pages()->create([
            'slug' => $slug, 'locale' => $locale, 'title' => $title,
            'blocks' => [['type' => 'text', 'heading' => $title, 'body' => [$title]]],
            'status' => $status, 'published_at' => $status === Page::STATUS_PUBLISHED ? now()->subDay() : null,
        ]);
    }

    private function newsPost(string $slug, string $locale, string $title): Post
    {
        $post = new Post(['slug' => $slug, 'locale' => $locale, 'title' => $title, 'body' => $title, 'status' => Post::STATUS_PUBLISHED, 'published_at' => now()->subDay()]);
        $post->tenant_id = $this->tenant->id;
        $post->save();

        return $post;
    }

    public function test_without_published_translations_there_is_no_switcher_and_no_language_prefix(): void
    {
        $this->page('history', 'en', 'School History', Page::STATUS_DRAFT);

        $this->get('/history')->assertOk()->assertInertia(fn ($page) => $page->where('publicLocale.switcher', []));
        $this->get('/en/history')->assertNotFound();
        $this->get('/en')->assertNotFound();
    }

    public function test_a_published_translation_gets_its_own_url_switcher_and_hreflang(): void
    {
        $this->page('history', 'en', 'School History');

        $this->get('/en/history')->assertOk()
            ->assertInertia(fn ($page) => $page->component('public/page')
                ->where('page.title', 'School History')
                ->where('publicLocale.current', 'en')
                ->where('publicLocale.alternates', ['ka' => '/history', 'en' => '/en/history']))
            ->assertSee('hreflang="en"', false)
            ->assertSee('<html lang="en">', false);

        // From the Georgian page, EN is the same page; a page without a
        // translation sends EN to the English start page instead.
        $this->get('/history')->assertOk()->assertInertia(fn ($page) => $page
            ->where('publicLocale.switcher.1', ['code' => 'en', 'label' => 'EN', 'url' => '/en/history', 'current' => false, 'sameContent' => true]));
        $this->page('contact', 'ka', 'კონტაქტი');
        $this->get('/contact')->assertOk()->assertInertia(fn ($page) => $page->where('publicLocale.switcher.1.url', '/en')->where('publicLocale.switcher.1.sameContent', false));

        $this->get('/en')->assertOk()->assertInertia(fn ($page) => $page->component('public/locale-home')->where('pages.0.url', '/en/history'));
        $this->get('/ru/history')->assertNotFound();
        $this->get('/en/contact')->assertNotFound();
    }

    public function test_each_language_lists_only_its_own_news_and_the_sitemap_has_every_language(): void
    {
        $this->page('history', 'en', 'School History');
        $this->newsPost('partnership', 'ka', 'პარტნიორობა');
        $this->newsPost('partnership', 'en', 'Partnership');

        $this->get('/news')->assertOk()->assertInertia(fn ($page) => $page->has('posts.data', 1)->where('posts.data.0.title', 'პარტნიორობა'));
        $this->get('/en/news')->assertOk()->assertInertia(fn ($page) => $page->has('posts.data', 1)->where('posts.data.0.title', 'Partnership'));
        $this->get('/en/news/partnership')->assertOk()->assertInertia(fn ($page) => $page->where('post.title', 'Partnership'));
        $this->get('/news/partnership')->assertOk()->assertInertia(fn ($page) => $page->where('post.title', 'პარტნიორობა'));

        $sitemap = $this->get('/sitemap.xml')->assertOk()->getContent();
        $this->assertStringContainsString('/en/history</loc>', (string) $sitemap);
        $this->assertStringContainsString('/en/news/partnership</loc>', (string) $sitemap);
    }
}
