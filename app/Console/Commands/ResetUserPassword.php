<?php

namespace App\Console\Commands;

use App\Domain\Governance\AuditLogger;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * The documented, audited emergency password-recovery path recommended by
 * the 2026-09-14 (Assignments) and 2026-09-15 (Substitution) production
 * verification entries in docs/implementation-status.md: production's
 * one-time seeded admin password was never captured, there is no
 * password-reset flow usable without mailbox access, and the session store
 * (Redis) cannot be read/faked to manufacture a session. Both entries
 * flagged the same gap and asked for exactly this — a command in place of
 * an ad-hoc `php artisan tinker` password overwrite, so the action is
 * reviewable (signature, this docblock) and always leaves an audit trail
 * (CLAUDE.md invariant #7) instead of a silent DB write.
 *
 * Deliberately requires an explicit --tenant and only resets a user who
 * holds an active membership there — this is an operator tool run over an
 * already-privileged SSH session (the same trust level as `tinker`), not a
 * web endpoint, but it still must not become "reset any email's password
 * with no context recorded". A --reason is mandatory so the audit_events
 * row means something later; the generated password is shown exactly once
 * and is never written to a log, the audit trail, or disk.
 */
class ResetUserPassword extends Command
{
    protected $signature = 'admin:reset-password
        {email : Email of the existing user whose password should be reset}
        {--tenant= : Tenant slug the user must hold an active membership in}
        {--reason= : Why this recovery is happening, recorded on the audit trail (never the password itself)}
        {--force : Skip the interactive confirmation prompt}';

    protected $description = 'Emergency, audited password reset for a user with an active membership in a tenant (documented admin/director/academic_manager recovery path)';

    public function handle(AuditLogger $auditLogger): int
    {
        $email = (string) $this->argument('email');
        $tenantSlug = $this->option('tenant');
        $reason = trim((string) $this->option('reason'));

        if (! $tenantSlug) {
            $this->error('--tenant=<slug> is required — this command never guesses which school the reset is for.');

            return self::FAILURE;
        }

        if (mb_strlen($reason) < 10) {
            $this->error('--reason="..." is required (at least 10 characters) — it is the only record of why this password was reset, since the new password itself is never logged.');

            return self::FAILURE;
        }

        $tenant = Tenant::query()->where('slug', $tenantSlug)->first();

        if (! $tenant) {
            $this->error("No tenant found with slug \"{$tenantSlug}\".");

            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            $this->error("No user found with email \"{$email}\".");

            return self::FAILURE;
        }

        $activeRoles = TenantMembership::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->pluck('role')
            ->all();

        if ($activeRoles === []) {
            $this->error("\"{$email}\" has no active membership in tenant \"{$tenantSlug}\" — refusing to reset a password outside that context.");

            return self::FAILURE;
        }

        $this->line("User:   {$email}");
        $this->line("Tenant: {$tenant->name} ({$tenant->slug})");
        $this->line('Roles:  '.implode(', ', $activeRoles));
        $this->line("Reason: {$reason}");

        if (! $this->option('force') && ! $this->confirm('Reset this password now? This is recorded on the audit trail and cannot be undone.')) {
            $this->warn('Aborted — no password was changed.');

            return self::SUCCESS;
        }

        $newPassword = Str::password(24);

        // The `password` cast on User is `hashed`, so assigning the plain
        // value here hashes it on save — the plaintext is never persisted.
        $user->password = $newPassword;
        $user->setRememberToken(Str::random(60));
        $user->save();

        $auditLogger->record(
            tenantId: $tenant->id,
            action: 'user.password_reset_via_cli',
            subject: $user,
            actorId: null,
            meta: [
                'reason' => $reason,
                'roles_at_reset' => $activeRoles,
                'performed_via' => 'admin:reset-password',
            ],
        );

        $this->newLine();
        $this->info('Password reset. Shown once below — it is not stored anywhere in plaintext (not logged, not in the audit trail):');
        $this->newLine();
        $this->line("    {$newPassword}");
        $this->newLine();
        $this->warn('Log in now and change it immediately at /settings/security — this value will not be shown or recoverable again.');

        return self::SUCCESS;
    }
}
