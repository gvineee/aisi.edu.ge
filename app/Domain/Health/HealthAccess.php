<?php

namespace App\Domain\Health;

use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\Student;
use App\Domain\Academics\Models\TeacherAssignment;
use App\Domain\Health\Models\HealthAccessEvent;
use App\Domain\Health\Models\HealthEmergencyAccess;
use App\Domain\Health\Models\HealthPermissionGrant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The one place that answers "may this user see this student's health
 * data, and how much" (CLAUDE-PLATFORM-MODULES §8):
 *
 *  - `full`       — staff holding health.view / health.manage, with 2FA on;
 *  - `guardian`   — a guardian with an active link to the student;
 *  - `emergency`  — a staff member's own unexpired, reasoned break-glass
 *                   access for this student;
 *  - `alerts_only`— the student's teachers: only staff-facing alerts;
 *  - `none`.
 *
 * Broad roles (admin, director) grant nothing here by themselves.
 */
class HealthAccess
{
    public const BASIS_FULL = 'full';

    public const BASIS_GUARDIAN = 'guardian';

    public const BASIS_EMERGENCY = 'emergency';

    public const BASIS_ALERTS_ONLY = 'alerts_only';

    public const BASIS_NONE = 'none';

    /**
     * @var array<int, string>
     */
    private const STAFF_ROLES = [
        TenantMembership::ROLE_TEACHER,
        TenantMembership::ROLE_ACADEMIC_MANAGER,
        TenantMembership::ROLE_ACCOUNTANT,
        TenantMembership::ROLE_EDITOR,
        TenantMembership::ROLE_DIRECTOR,
        TenantMembership::ROLE_ADMIN,
    ];

    /**
     * @return array<int, string>
     */
    public function capabilities(int $tenantId, User $user): array
    {
        if (! $this->isStaff($tenantId, $user)) {
            return [];
        }

        return HealthPermissionGrant::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->pluck('capability')
            ->unique()
            ->values()
            ->all();
    }

    public function can(int $tenantId, User $user, string $capability): bool
    {
        return in_array($capability, $this->capabilities($tenantId, $user), true);
    }

    /**
     * Health-privileged capabilities additionally require confirmed 2FA
     * (CLAUDE-PLATFORM-MODULES §15: MFA for health privileged roles).
     */
    public function hasMfa(User $user): bool
    {
        return $user->two_factor_confirmed_at !== null;
    }

    public function isStaff(int $tenantId, User $user): bool
    {
        return TenantMembership::userHasAnyActiveRole($tenantId, $user->id, self::STAFF_ROLES);
    }

    /**
     * @return array{basis: string, emergency: HealthEmergencyAccess|null, needsMfa: bool}
     */
    public function resolve(int $tenantId, User $user, Student $student): array
    {
        $capabilities = $this->capabilities($tenantId, $user);
        $hasFullCapability = array_intersect($capabilities, [HealthPermissionGrant::VIEW, HealthPermissionGrant::MANAGE]) !== [];

        if ($hasFullCapability && $this->hasMfa($user)) {
            return ['basis' => self::BASIS_FULL, 'emergency' => null, 'needsMfa' => false];
        }

        if ($this->isGuardianOf($tenantId, $user, $student)) {
            return ['basis' => self::BASIS_GUARDIAN, 'emergency' => null, 'needsMfa' => false];
        }

        $emergency = $this->activeEmergency($tenantId, $user, $student);

        if ($emergency !== null) {
            return ['basis' => self::BASIS_EMERGENCY, 'emergency' => $emergency, 'needsMfa' => false];
        }

        if ($this->teachesStudent($tenantId, $user, $student)) {
            return ['basis' => self::BASIS_ALERTS_ONLY, 'emergency' => null, 'needsMfa' => $hasFullCapability];
        }

        return ['basis' => self::BASIS_NONE, 'emergency' => null, 'needsMfa' => $hasFullCapability];
    }

    public function activeEmergency(int $tenantId, User $user, Student $student): ?HealthEmergencyAccess
    {
        return HealthEmergencyAccess::query()
            ->where('tenant_id', $tenantId)
            ->where('student_id', $student->id)
            ->where('user_id', $user->id)
            ->where('expires_at', '>', Carbon::now())
            ->latest('id')
            ->first();
    }

    public function isGuardianOf(int $tenantId, User $user, Student $student): bool
    {
        return TenantMembership::userHasActiveRole($tenantId, $user->id, TenantMembership::ROLE_GUARDIAN)
            && GuardianLink::query()
                ->where('tenant_id', $tenantId)
                ->where('user_id', $user->id)
                ->where('student_id', $student->id)
                ->where('is_active', true)
                ->exists();
    }

    public function teachesStudent(int $tenantId, User $user, Student $student): bool
    {
        if ($student->school_class_id === null
            || ! TenantMembership::userHasActiveRole($tenantId, $user->id, TenantMembership::ROLE_TEACHER)) {
            return false;
        }

        return TeacherAssignment::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->where('school_class_id', $student->school_class_id)
            ->exists()
            || SchoolClass::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($student->school_class_id)
                ->where('homeroom_teacher_id', $user->id)
                ->exists();
    }

    /**
     * Records an access event. Holds the action and basis only — never the
     * condition, phone number or note itself.
     */
    public function log(int $tenantId, Student $student, User $actor, string $action, string $basis, ?HealthEmergencyAccess $emergency = null): void
    {
        $event = new HealthAccessEvent([
            'student_id' => $student->id,
            'actor_id' => $actor->id,
            'action' => $action,
            'access_basis' => $basis,
            'emergency_access_id' => $emergency?->id,
        ]);
        $event->tenant_id = $tenantId;
        $event->save();
    }
}
