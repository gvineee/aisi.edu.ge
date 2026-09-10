<?php

namespace Tests\Feature;

use App\Domain\Content\Models\Page;
use App\Domain\Tenancy\Models\TenantDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_a_successful_response()
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
            'seo_title' => 'Test',
            'seo_description' => 'Test',
        ]);

        $response = $this->get(route('home'));

        $response->assertOk();
    }
}
