<?php

namespace App\Domain\Academics\Actions;

use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Academics\Models\Student;
use App\Domain\Governance\AuditLogger;
use App\Models\User;

/**
 * Admin-side maintenance of guardian ↔ student links after the first
 * invitation: linking an existing guardian to another child, changing the
 * link's permissions, and (de)activating it. Every change is a guardian
 * link change under CLAUDE.md invariant #7, so each one is audit-logged
 * with only the permission diff — never the guardian's personal data.
 *
 * Links are never deleted: deactivating keeps history and immediately
 * removes access everywhere access is checked through `is_active`.
 */
class ManageGuardianLinks
{
    /**
     * @var array<int, string>
     */
    public const PERMISSIONS = ['can_view_academic', 'can_view_financial', 'can_pickup', 'can_receive_notifications'];

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * Creates the link, or reactivates/updates an existing (possibly
     * inactive) one for the same pair — the table is unique per pair.
     *
     * @param  array<string, bool>  $permissions
     */
    public function link(Student $student, User $guardian, array $permissions, User $actor): GuardianLink
    {
        $link = GuardianLink::query()
            ->where('tenant_id', $student->tenant_id)
            ->where('user_id', $guardian->id)
            ->where('student_id', $student->id)
            ->first();

        $created = $link === null;

        if ($link === null) {
            $link = new GuardianLink(['user_id' => $guardian->id, 'student_id' => $student->id]);
            $link->tenant_id = $student->tenant_id;
        }

        foreach (self::PERMISSIONS as $permission) {
            $link->{$permission} = $permissions[$permission] ?? false;
        }

        $link->is_active = true;
        $link->save();

        $this->auditLogger->record($student->tenant_id, $created ? 'guardian_link.created' : 'guardian_link.reactivated', $link, $actor->id, [
            'guardian_user_id' => $guardian->id,
            'student_id' => $student->id,
            'permissions' => $this->stateOf($link),
        ]);

        return $link;
    }

    /**
     * @param  array<string, bool>  $permissions
     */
    public function update(GuardianLink $link, array $permissions, bool $isActive, User $actor): void
    {
        $before = $this->stateOf($link);

        foreach (self::PERMISSIONS as $permission) {
            $link->{$permission} = $permissions[$permission] ?? false;
        }

        $link->is_active = $isActive;

        if (! $link->isDirty()) {
            return;
        }

        $link->save();

        $after = $this->stateOf($link);
        $changes = [];

        foreach ($after as $key => $value) {
            if ($before[$key] !== $value) {
                $changes[$key] = ['from' => $before[$key], 'to' => $value];
            }
        }

        $action = match (true) {
            $before['is_active'] && ! $isActive => 'guardian_link.deactivated',
            ! $before['is_active'] && $isActive => 'guardian_link.reactivated',
            default => 'guardian_link.permissions_updated',
        };

        $this->auditLogger->record($link->tenant_id, $action, $link, $actor->id, [
            'guardian_user_id' => $link->user_id,
            'student_id' => $link->student_id,
            'changes' => $changes,
        ]);
    }

    /**
     * @return array<string, bool>
     */
    private function stateOf(GuardianLink $link): array
    {
        $state = [];

        foreach ([...self::PERMISSIONS, 'is_active'] as $field) {
            $state[$field] = (bool) $link->{$field};
        }

        return $state;
    }
}
