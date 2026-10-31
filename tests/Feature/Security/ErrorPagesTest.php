<?php

namespace Tests\Feature\Security;

use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Audit A11/A12: branded Georgian error screens with a way back (the HTTP
 * status unchanged, nothing about the refused record shown), and a
 * platform operator landing in the platform console, not on the
 * "verify your child" page.
 */
class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unknown_address_gets_the_schools_404_page(): void
    {
        $this->get('/no-such-page/at-all')->assertNotFound()->assertInertia(fn ($page) => $page
            ->component('error')
            ->where('status', 404)
            ->whereNot('brand', null)
        );
    }

    public function test_a_refused_portal_page_keeps_403_and_shows_the_error_screen(): void
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->firstOrFail()->tenant;
        $librarian = User::factory()->create();
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $librarian->id, 'role' => TenantMembership::ROLE_LIBRARIAN, 'is_active' => true]);

        $this->actingAs($librarian)->get('/portal/members')->assertForbidden()->assertInertia(fn ($page) => $page
            ->component('error')
            ->where('status', 403)
        );
    }

    public function test_json_callers_keep_json(): void
    {
        $this->getJson('/no-such-page')->assertNotFound()->assertJsonStructure(['message']);
    }

    public function test_a_platform_operator_starts_in_the_platform_console(): void
    {
        $operator = User::factory()->create(['email' => 'ops@platform.test']);
        $this->artisan('platform:operator', ['email' => 'ops@platform.test'])->assertSuccessful();

        // Without MFA: sent to switch it on first.
        $this->actingAs($operator)->get('/dashboard')->assertRedirect(route('security.edit'));

        $operator->forceFill(['two_factor_confirmed_at' => now()])->save();
        $this->actingAs($operator)->get('/dashboard')->assertRedirect(route('platform.index'));
        // No school membership was created along the way.
        $this->assertFalse(TenantMembership::query()->where('user_id', $operator->id)->exists());

        $this->actingAs($operator)->get('/settings/profile')->assertInertia(fn ($page) => $page
            ->where('platformOperator', true)
        );
    }
}
