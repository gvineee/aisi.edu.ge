<?php

namespace Tests\Feature;

use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A controller's `->with('toast', ...)` must reach the client as Inertia
 * flash data (the `flash` router event) on the page the redirect lands on,
 * exactly once.
 */
class FlashToastTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_session_toast_is_delivered_once_as_inertia_flash(): void
    {
        $this->withoutVite();
        $tenant = TenantDomain::query()->where('domain', 'localhost')->firstOrFail()->tenant;
        $director = User::factory()->create();
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $director->id, 'role' => TenantMembership::ROLE_DIRECTOR, 'is_active' => true]);

        $this->actingAs($director)->from('/documents/settings')
            ->post('/documents/categories', ['name' => 'ოქმი'])
            ->assertRedirect('/documents/settings');

        $page = $this->actingAs($director)->get('/documents/settings')->assertOk()->viewData('page');
        $this->assertSame(['type' => 'success', 'message' => 'კატეგორია დაემატა.'], $page['flash']['toast'] ?? null);

        $next = $this->actingAs($director)->get('/documents/settings')->viewData('page');
        $this->assertArrayNotHasKey('flash', $next);
    }
}
