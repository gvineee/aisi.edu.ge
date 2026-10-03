<?php

namespace Tests\Feature\Security;

use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CLAUDE.md #4: a tenant admin without confirmed 2FA is sent to enrol
 * before using the portal; settings and the public site stay reachable,
 * other roles are unaffected, and an enrolled admin passes.
 */
class AdminMfaEnforcementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_without_2fa_is_sent_to_enrol_but_never_locked_out(): void
    {
        config(['security.admin_mfa_required' => true]);
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;
        $admin = User::factory()->create();
        $teacher = User::factory()->create();
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $admin->id, 'role' => TenantMembership::ROLE_ADMIN, 'is_active' => true]);
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $teacher->id, 'role' => TenantMembership::ROLE_TEACHER, 'is_active' => true]);

        $this->actingAs($admin)->get('/dashboard')->assertRedirect(route('security.edit'));
        $this->actingAs($admin)->get('/portal/members')->assertRedirect(route('security.edit'));
        $this->assertNotSame(302, $this->actingAs($admin)->get('/')->status());
        $this->assertNotSame(302, $this->actingAs($admin)->get('/settings/profile')->status());

        $this->actingAs($teacher)->get('/dashboard')->assertOk();

        $director = User::factory()->create();
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $director->id, 'role' => TenantMembership::ROLE_DIRECTOR, 'is_active' => true]);
        $this->actingAs($director)->get('/portal/director')->assertRedirect(route('security.edit'));

        $admin->forceFill(['two_factor_confirmed_at' => now()])->save();
        $this->actingAs($admin)->get('/dashboard')->assertOk();
    }

    public function test_responses_carry_baseline_security_headers(): void
    {
        $response = $this->get('/robots.txt');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeaderMissing('Strict-Transport-Security');

        $this->get('https://localhost/robots.txt')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }
}
