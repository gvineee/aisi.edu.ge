<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Models\Page;
use App\Domain\Content\Models\Post;
use App\Domain\Tenancy\Models\TenantDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CLAUDE.md requires public page title/description/content to be present in
 * the initial HTML without JS. We don't run Inertia SSR (see
 * docs/implementation-status.md), so app.blade.php reads the per-page SEO
 * props directly instead — this test locks that path in.
 */
class HomePageSeoTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_page_seo_title_and_description_are_in_the_raw_html(): void
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;

        $tenant->pages()->create([
            'slug' => 'home',
            'locale' => $tenant->locale,
            'title' => 'მთავარი',
            'excerpt' => null,
            'blocks' => [],
            'status' => Page::STATUS_PUBLISHED,
            'published_at' => now(),
            'seo_title' => 'უნიკალური სათაური საძიებოსთვის',
            'seo_description' => 'უნიკალური აღწერა საძიებოსთვის',
        ]);

        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertSee('<title>უნიკალური სათაური საძიებოსთვის</title>', false);
        $response->assertSee('name="description" content="უნიკალური აღწერა საძიებოსთვის"', false);
    }

    public function test_public_page_text_is_in_the_raw_html_and_escaped(): void
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;

        $tenant->pages()->create([
            'slug' => 'about',
            'locale' => $tenant->locale,
            'title' => 'სკოლის შესახებ',
            'excerpt' => null,
            'blocks' => [
                ['type' => 'text', 'heading' => 'ჩვენი ისტორია', 'body' => ['პირველი აბზაცი', 'მეორე <script>alert(1)</script>']],
                ['type' => 'programs', 'heading' => 'პროგრამები', 'items' => [['title' => 'დაწყებითი', 'grade' => 'I–VI', 'body' => 'აღწერა']]],
            ],
            'status' => Page::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $response = $this->get('/about');

        $response->assertOk();
        $response->assertSee('<div id="public-content">', false);
        $response->assertSee('<h1>სკოლის შესახებ</h1>', false);
        $response->assertSee('<h2>ჩვენი ისტორია</h2>', false);
        $response->assertSee('<p>პირველი აბზაცი</p>', false);
        $response->assertSee('<h3>დაწყებითი</h3>', false);
        $response->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
        $response->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_news_post_body_is_in_the_raw_html(): void
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;

        $tenant->posts()->create([
            'slug' => 'sporti',
            'locale' => $tenant->locale,
            'title' => 'სპორტის დღე',
            'excerpt' => 'მოკლედ',
            'body' => 'სრული ტექსტი სპორტის დღეზე',
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $this->get('/news')->assertOk()->assertSee('<a href="/news/sporti">სპორტის დღე</a>', false);
        $this->get('/news/sporti')->assertOk()
            ->assertSee('<h1>სპორტის დღე</h1>', false)
            ->assertSee('სრული ტექსტი სპორტის დღეზე', false);
    }

    public function test_private_pages_do_not_get_the_public_content_block(): void
    {
        $this->get('/login')->assertOk()->assertDontSee('id="public-content"', false);
    }
}
