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

    public function test_privileged_changes_need_a_recent_password_confirmation(): void
    {
        config(['security.privileged_reauth' => true]);
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;
        $admin = User::factory()->create();
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $admin->id, 'role' => TenantMembership::ROLE_ADMIN, 'is_active' => true]);

        $this->actingAs($admin)->from('/portal/members')
            ->post('/portal/members/invite', ['email' => 'new@school.test', 'role' => TenantMembership::ROLE_EDITOR])
            ->assertRedirect(route('password.confirm'))
            ->assertSessionHas('url.intended', url('/portal/members'));
        $this->assertDatabaseMissing('tenant_invitations', ['email' => 'new@school.test']);

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->post('/portal/members/invite', ['email' => 'new@school.test', 'role' => TenantMembership::ROLE_EDITOR])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('tenant_invitations', ['email' => 'new@school.test']);
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

    public function test_csp_nonce_matches_the_inline_script_and_mode_is_configurable(): void
    {
        $html = $this->get('/login');
        $policy = (string) $html->headers->get('Content-Security-Policy-Report-Only');
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-([A-Za-z0-9]+)'/", $policy);
        preg_match("/'nonce-([A-Za-z0-9]+)'/", $policy, $m);
        $this->assertStringContainsString('<script nonce="'.$m[1].'">', (string) $html->getContent());
        $this->assertNull($html->headers->get('Content-Security-Policy'));

        config(['security.csp' => 'enforce']);
        $this->assertNotNull($this->get('/login')->headers->get('Content-Security-Policy'));

        config(['security.csp' => 'off']);
        $off = $this->get('/login');
        $this->assertNull($off->headers->get('Content-Security-Policy'));
        $this->assertNull($off->headers->get('Content-Security-Policy-Report-Only'));
    }
}
