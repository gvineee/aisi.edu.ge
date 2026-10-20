<?php

namespace App\Domain\Library;

use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\Student;
use App\Domain\Academics\Models\TeacherAssignment;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who may do what in the library and with class book lists (CLAUDE.md #2-3):
 * - desk (librarian, academic manager, director, admin): catalog, copies,
 *   loans, reservations, every class book list;
 * - a teacher edits the book lists of classes they are assigned to or are
 *   homeroom teacher of;
 * - a guardian sees the lists, loans and reservations of children whose
 *   active link carries can_view_academic, and may reserve for them;
 * - a student sees their own.
 */
class LibraryAccess
{
    /** @var array<int, string> */
    public const DESK_ROLES = [
        TenantMembership::ROLE_LIBRARIAN,
        TenantMembership::ROLE_ACADEMIC_MANAGER,
        TenantMembership::ROLE_DIRECTOR,
        TenantMembership::ROLE_ADMIN,
    ];

    public function isDesk(int $tenantId, User $user): bool
    {
        return TenantMembership::userHasAnyActiveRole($tenantId, $user->id, self::DESK_ROLES);
    }

    /**
     * Students this user may see library data of and reserve for (not the
     * desk's whole roster): own children, or themselves.
     *
     * @return Collection<int, Student>
     */
    public function familyStudents(int $tenantId, User $user): Collection
    {
        $students = collect();

        if (TenantMembership::userHasActiveRole($tenantId, $user->id, TenantMembership::ROLE_GUARDIAN)) {
            $students = GuardianLink::query()->where('tenant_id', $tenantId)->where('user_id', $user->id)
                ->where('is_active', true)->where('can_view_academic', true)
                ->with('student.schoolClass')->get()->pluck('student')->filter();
        }

        if (TenantMembership::userHasActiveRole($tenantId, $user->id, TenantMembership::ROLE_STUDENT)) {
            $students = $students->merge(Student::query()->where('tenant_id', $tenantId)->where('user_id', $user->id)->with('schoolClass')->get());
        }

        /** @var Collection<int, Student> $unique */
        $unique = $students->filter(fn (Student $s) => $s->is_active)->unique('id')->values();

        return $unique;
    }

    /**
     * Class ids whose book list this user may edit.
     *
     * @return Collection<int, int>
     */
    public function editableClassIds(int $tenantId, User $user): Collection
    {
        if ($this->isDesk($tenantId, $user)) {
            return SchoolClass::query()->where('tenant_id', $tenantId)->pluck('id');
        }

        if (! TenantMembership::userHasActiveRole($tenantId, $user->id, TenantMembership::ROLE_TEACHER)) {
            return collect();
        }

        return TeacherAssignment::query()->where('tenant_id', $tenantId)->where('user_id', $user->id)->pluck('school_class_id')
            ->merge(SchoolClass::query()->where('tenant_id', $tenantId)->where('homeroom_teacher_id', $user->id)->pluck('id'))
            ->unique()->values();
    }

    public function canEditList(int $tenantId, User $user, SchoolClass $class): bool
    {
        return $class->tenant_id === $tenantId && $this->editableClassIds($tenantId, $user)->contains($class->id);
    }
}
