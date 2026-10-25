<?php

namespace App\Domain\Academics\Actions;

use App\Domain\Academics\Models\ClassStaffRole;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Governance\AuditLogger;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Timetable\Models\Room;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Edits a class's profile: grade level, short name, home room and the
 * co-homeroom teachers („დამრიგებელი 2“). The primary homeroom teacher
 * keeps its own action (AssignHomeroomTeacher). Staff-role changes are
 * audit-logged like any role change.
 */
class UpdateClassProfile
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  list<int>  $coHomeroomUserIds
     */
    public function handle(SchoolClass $schoolClass, User $actor, ?int $gradeLevel, ?string $shortName, ?int $homeRoomId, array $coHomeroomUserIds): void
    {
        $tenantId = $schoolClass->tenant_id;

        if ($homeRoomId !== null && ! Room::query()->where('tenant_id', $tenantId)->whereKey($homeRoomId)->exists()) {
            throw ValidationException::withMessages(['home_room_id' => 'არასწორი ოთახი.']);
        }

        $coHomeroomUserIds = array_values(array_unique(array_diff($coHomeroomUserIds, [$schoolClass->homeroom_teacher_id])));

        foreach ($coHomeroomUserIds as $userId) {
            if (! TenantMembership::userHasActiveRole($tenantId, $userId, TenantMembership::ROLE_TEACHER)) {
                throw ValidationException::withMessages(['co_homeroom_ids' => 'არჩეული პირი ამ სკოლის აქტიური მასწავლებელი არ არის.']);
            }
        }

        DB::transaction(function () use ($schoolClass, $actor, $gradeLevel, $shortName, $homeRoomId, $coHomeroomUserIds, $tenantId) {
            $schoolClass->fill(['grade_level' => $gradeLevel, 'short_name' => $shortName, 'home_room_id' => $homeRoomId]);
            $changed = array_keys($schoolClass->getDirty());
            $schoolClass->save();

            $current = ClassStaffRole::query()
                ->where('tenant_id', $tenantId)
                ->where('school_class_id', $schoolClass->id)
                ->where('role', ClassStaffRole::ROLE_CO_HOMEROOM)
                ->pluck('user_id')
                ->all();

            $added = array_values(array_diff($coHomeroomUserIds, $current));
            $removed = array_values(array_diff($current, $coHomeroomUserIds));

            ClassStaffRole::query()
                ->where('tenant_id', $tenantId)
                ->where('school_class_id', $schoolClass->id)
                ->where('role', ClassStaffRole::ROLE_CO_HOMEROOM)
                ->whereIn('user_id', $removed)
                ->delete();

            foreach ($added as $userId) {
                $role = new ClassStaffRole(['school_class_id' => $schoolClass->id, 'user_id' => $userId, 'role' => ClassStaffRole::ROLE_CO_HOMEROOM]);
                $role->tenant_id = $tenantId;
                $role->save();
            }

            if ($changed !== [] || $added !== [] || $removed !== []) {
                $this->auditLogger->record($tenantId, 'school_class.profile_updated', $schoolClass, $actor->id, [
                    'changed_fields' => $changed,
                    'co_homeroom_added' => $added,
                    'co_homeroom_removed' => $removed,
                ]);
            }
        });
    }
}
