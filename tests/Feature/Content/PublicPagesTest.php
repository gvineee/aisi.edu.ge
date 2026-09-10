<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Models\Page;
use App\Domain\Content\Models\Post;
use App\Domain\Tenancy\Models\TenantDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_published_non_home_page_renders_at_its_slug(): void
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;

        $tenant->pages()->create([
            'slug' => 'about', 'locale' => $tenant->locale, 'title' => 'About',
            'blocks' => [['type' => 'text', 'heading' => 'Mission', 'body' => 'Body text']],
            'status' => Page::STATUS_PUBLISHED, 'published_at' => now(),
        ]);

        $this->get('/about')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('page.slug', 'about'));
    }

    public function test_home_is_not_reachable_at_slash_home(): void
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;

        $tenant->pages()->create([
            'slug' => 'home', 'locale' => $tenant->locale, 'title' => 'Home',
            'blocks' => [], 'status' => Page::STATUS_PUBLISHED, 'published_at' => now(),
        ]);

        // Canonical home URL is "/", not "/home" — the catch-all explicitly
        // refuses that slug so there is exactly one URL for it.
        $this->get('/home')->assertNotFound();
    }

    public function test_a_draft_page_is_not_publicly_reachable(): void
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;

        $tenant->pages()->create([
            'slug' => 'about', 'locale' => $tenant->locale, 'title' => 'About',
            'blocks' => [], 'status' => Page::STATUS_DRAFT, 'published_at' => null,
        ]);

        $this->get('/about')->assertNotFound();
    }

    public function test_news_index_lists_only_published_posts(): void
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;

        $tenant->posts()->create([
            'slug' => 'published-story', 'locale' => $tenant->locale, 'title' => 'Published',
            'body' => 'Body', 'status' => Post::STATUS_PUBLISHED, 'published_at' => now(),
        ]);
        $tenant->posts()->create([
            'slug' => 'draft-story', 'locale' => $tenant->locale, 'title' => 'Draft',
            'body' => 'Body', 'status' => Post::STATUS_DRAFT, 'published_at' => null,
        ]);

        $response = $this->get('/news');

        $response->assertOk();
        $response->assertSee('Published');
        $response->assertDontSee('Draft');
    }

    public function test_news_show_renders_a_published_post(): void
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;

        $tenant->posts()->create([
            'slug' => 'a-story', 'locale' => $tenant->locale, 'title' => 'A Story',
            'body' => 'Full body text', 'status' => Post::STATUS_PUBLISHED, 'published_at' => now(),
        ]);

        $this->get('/news/a-story')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('post.title', 'A Story'));
    }

    public function test_a_draft_post_is_not_publicly_reachable(): void
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;

        $tenant->posts()->create([
            'slug' => 'a-story', 'locale' => $tenant->locale, 'title' => 'A Story',
            'body' => 'Body', 'status' => Post::STATUS_DRAFT, 'published_at' => null,
        ]);

        $this->get('/news/a-story')->assertNotFound();
    }
}
