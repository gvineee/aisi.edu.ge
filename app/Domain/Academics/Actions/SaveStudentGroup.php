<?php

namespace App\Domain\Academics\Actions;

use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\Student;
use App\Domain\Academics\Models\StudentGroup;
use App\Domain\Governance\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates or edits a teaching group of one class and sets its members. A
 * member must be an active pupil of that same class; nobody is added to a
 * group automatically.
 */
class SaveStudentGroup
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  list<int>  $studentIds
     */
    public function handle(SchoolClass $schoolClass, User $actor, ?StudentGroup $group, string $name, array $studentIds): StudentGroup
    {
        $tenantId = $schoolClass->tenant_id;
        $studentIds = array_values(array_unique($studentIds));

        $valid = Student::query()
            ->where('tenant_id', $tenantId)
            ->where('school_class_id', $schoolClass->id)
            ->where('is_active', true)
            ->whereIn('id', $studentIds)
            ->count();

        if ($valid !== count($studentIds)) {
            throw ValidationException::withMessages(['student_ids' => 'ჯგუფში მხოლოდ ამ კლასის აქტიური მოსწავლე შეიძლება იყოს.']);
        }

        $duplicate = StudentGroup::query()
            ->where('tenant_id', $tenantId)
            ->where('school_class_id', $schoolClass->id)
            ->where('name', $name)
            ->when($group !== null, fn ($query) => $query->whereKeyNot($group->id))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['name' => 'ამ კლასს ასეთი ჯგუფი უკვე აქვს.']);
        }

        return DB::transaction(function () use ($schoolClass, $actor, $group, $name, $studentIds, $tenantId) {
            $created = $group === null;
            $group ??= new StudentGroup(['school_class_id' => $schoolClass->id]);
            $group->tenant_id = $tenantId;
            $group->name = $name;
            $group->save();

            $group->students()->sync(array_fill_keys($studentIds, ['tenant_id' => $tenantId]));

            $this->auditLogger->record($tenantId, $created ? 'student_group.created' : 'student_group.updated', $group, $actor->id, [
                'school_class_id' => $schoolClass->id,
                'members' => count($studentIds),
            ]);

            return $group;
        });
    }
}
