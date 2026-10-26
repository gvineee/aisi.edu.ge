<?php

namespace App\Domain\Academics\Actions;

use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\StaffMember;
use App\Domain\Academics\Models\TeacherAssignment;
use App\Domain\Governance\AuditLogger;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Timetable\Models\Lesson;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gives a staff-directory entry its login: an active member of the same
 * school chosen by an administrator (never matched by name). From then on
 * the teacher portal, attendance, substitutions and homeroom duties see the
 * person's lessons, workloads and classes. Unlinking reverses exactly that.
 */
class LinkStaffAccount
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function link(StaffMember $staff, User $user, User $actor): void
    {
        $isMember = TenantMembership::query()
            ->where('tenant_id', $staff->tenant_id)->where('user_id', $user->id)->where('is_active', true)->exists();

        if (! $isMember) {
            throw ValidationException::withMessages(['user_id' => 'ეს ანგარიში სკოლის აქტიური წევრი არ არის.']);
        }

        DB::transaction(function () use ($staff, $user, $actor) {
            $locked = StaffMember::query()->whereKey($staff->id)->lockForUpdate()->firstOrFail();
            $taken = StaffMember::query()->where('tenant_id', $locked->tenant_id)->where('user_id', $user->id)->whereKeyNot($locked->id)->exists();

            if ($taken) {
                throw ValidationException::withMessages(['user_id' => 'ეს ანგარიში უკვე სხვა თანამშრომელზეა მიბმული.']);
            }

            $locked->update(['user_id' => $user->id]);
            $counts = $this->stamp($locked, $user->id);

            $this->auditLogger->record($locked->tenant_id, 'staff.account_linked', $locked, $actor->id, ['user_id' => $user->id] + $counts);
        });
    }

    public function unlink(StaffMember $staff, User $actor): void
    {
        DB::transaction(function () use ($staff, $actor) {
            $locked = StaffMember::query()->whereKey($staff->id)->lockForUpdate()->firstOrFail();
            $previous = $locked->user_id;

            if ($previous === null) {
                return;
            }

            $counts = $this->stamp($locked, null);
            $locked->update(['user_id' => null]);

            $this->auditLogger->record($locked->tenant_id, 'staff.account_unlinked', $locked, $actor->id, ['user_id' => $previous] + $counts);
        });
    }

    /**
     * @return array{lessons: int, workloads: int, homerooms: int}
     */
    private function stamp(StaffMember $staff, ?int $userId): array
    {
        return [
            'lessons' => Lesson::query()->where('tenant_id', $staff->tenant_id)->where('staff_member_id', $staff->id)->update(['teacher_id' => $userId]),
            'workloads' => TeacherAssignment::query()->where('tenant_id', $staff->tenant_id)->where('staff_member_id', $staff->id)->update(['user_id' => $userId]),
            'homerooms' => SchoolClass::query()->where('tenant_id', $staff->tenant_id)->where('homeroom_staff_member_id', $staff->id)->update(['homeroom_teacher_id' => $userId]),
        ];
    }
}
