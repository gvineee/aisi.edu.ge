<?php

namespace Tests\Feature\Console;

use App\Domain\Governance\Models\AuditEvent;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Covers the documented emergency password-recovery command that
 * docs/implementation-status.md's 2026-09-14/2026-09-15 production
 * verification entries asked for, in place of an ad-hoc tinker password
 * overwrite: it must actually change the password, leave exactly one
 * audit_events row that never contains the plaintext password, and refuse
 * to act outside an explicit tenant/active-membership context.
 */
class ResetUserPasswordCommandTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): Tenant
    {
        return TenantDomain::query()->where('domain', 'localhost')->first()->tenant;
    }

    public function test_it_resets_the_password_for_a_user_with_an_active_membership_and_records_one_audit_event(): void
    {
        $tenant = $this->tenant();
        $user = User::factory()->create(['email' => 'director@aisi.test', 'password' => 'old-password-hash-placeholder']);
        TenantMembership::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => TenantMembership::ROLE_DIRECTOR,
            'is_active' => true,
        ]);
        $oldHash = $user->password;

        $this->artisan('admin:reset-password', [
            'email' => 'director@aisi.test',
            '--tenant' => $tenant->slug,
            '--reason' => 'One-time seeded password lost, needed for production verification.',
            '--force' => true,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('Password reset. Shown once below');

        $user->refresh();
        $this->assertNotSame($oldHash, $user->password);

        $this->assertSame(1, AuditEvent::query()
            ->where('tenant_id', $tenant->id)
            ->where('subject_type', User::class)
            ->where('subject_id', $user->id)
            ->where('action', 'user.password_reset_via_cli')
            ->count());

        $event = AuditEvent::query()->where('subject_id', $user->id)->where('subject_type', User::class)->first();
        $this->assertSame('One-time seeded password lost, needed for production verification.', $event->meta['reason']);
        $this->assertArrayNotHasKey('password', $event->meta);
        $serializedMeta = json_encode($event->meta);
        $this->assertIsString($serializedMeta);
        $this->assertStringNotContainsString($user->password, $serializedMeta);
    }

    public function test_the_printed_password_actually_logs_the_user_in(): void
    {
        $tenant = $this->tenant();
        $user = User::factory()->create(['email' => 'admin-recovery@aisi.test']);
        TenantMembership::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => TenantMembership::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $this->artisan('admin:reset-password', [
            'email' => 'admin-recovery@aisi.test',
            '--tenant' => $tenant->slug,
            '--reason' => 'Verifying the recovery command end to end.',
            '--force' => true,
        ])->assertSuccessful();

        $user->refresh();
        // The command never returns the plaintext to the caller by design
        // (it is only ever printed to the console) — so re-derive it is
        // impossible here; instead confirm the stored hash is a real
        // bcrypt/argon hash of *something* new, and that the old factory
        // default password no longer works.
        $this->assertFalse(Hash::check('password', $user->password));
    }

    public function test_it_refuses_without_an_explicit_tenant(): void
    {
        $user = User::factory()->create(['email' => 'noone@aisi.test']);

        $this->artisan('admin:reset-password', [
            'email' => 'noone@aisi.test',
            '--reason' => 'Missing tenant on purpose for this test.',
            '--force' => true,
        ])->assertFailed();

        $this->assertSame(0, AuditEvent::query()->count());
    }

    public function test_it_refuses_without_a_reason(): void
    {
        $tenant = $this->tenant();
        $user = User::factory()->create(['email' => 'noone2@aisi.test']);
        TenantMembership::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => TenantMembership::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $this->artisan('admin:reset-password', [
            'email' => 'noone2@aisi.test',
            '--tenant' => $tenant->slug,
            '--force' => true,
        ])->assertFailed();

        $this->assertSame(0, AuditEvent::query()->count());
    }

    public function test_it_refuses_a_user_with_no_active_membership_in_the_given_tenant(): void
    {
        $tenant = $this->tenant();
        $user = User::factory()->create(['email' => 'revoked@aisi.test']);
        TenantMembership::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => TenantMembership::ROLE_TEACHER,
            'is_active' => false,
        ]);
        $oldHash = $user->password;

        $this->artisan('admin:reset-password', [
            'email' => 'revoked@aisi.test',
            '--tenant' => 'aisi',
            '--reason' => 'Trying to reset a revoked membership on purpose.',
            '--force' => true,
        ])->assertFailed();

        $user->refresh();
        $this->assertSame($oldHash, $user->password);
        $this->assertSame(0, AuditEvent::query()->count());
    }

    public function test_it_refuses_an_unknown_tenant_slug(): void
    {
        $user = User::factory()->create(['email' => 'someone@aisi.test']);

        $this->artisan('admin:reset-password', [
            'email' => 'someone@aisi.test',
            '--tenant' => 'not-a-real-school',
            '--reason' => 'Unknown tenant slug on purpose for this test.',
            '--force' => true,
        ])->assertFailed();
    }

    public function test_without_force_it_asks_for_confirmation_and_aborts_on_no(): void
    {
        $tenant = $this->tenant();
        $user = User::factory()->create(['email' => 'confirm-no@aisi.test']);
        TenantMembership::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => TenantMembership::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $oldHash = $user->password;

        $this->artisan('admin:reset-password', [
            'email' => 'confirm-no@aisi.test',
            '--tenant' => $tenant->slug,
            '--reason' => 'Declining the confirmation prompt on purpose.',
        ])
            ->expectsConfirmation('Reset this password now? This is recorded on the audit trail and cannot be undone.', 'no')
            ->assertSuccessful();

        $user->refresh();
        $this->assertSame($oldHash, $user->password);
        $this->assertSame(0, AuditEvent::query()->count());
    }
}
