<?php

namespace App\Domain\Integrations\EduPage;

use App\Domain\Academics\EnrollmentHistory;
use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\Student;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The `asc-students` type: the pupils of EduPage's regular timetable data
 * (name, class, the teaching groups their lessons put them in). Run after
 * `asc-timetable`, whose classes and groups it resolves.
 *
 * Pupil data, so it takes the minimum: first and last name, class, groups.
 * No accounts, no guardians, no personal numbers. A pupil is keyed by
 * EduPage's id — stable across school years — so a later year's import
 * moves them to the new class with a dated enrollment instead of creating
 * them again. Nobody is matched by name: a same-named pupil already in the
 * class who did not come from EduPage is reported, never merged.
 *
 * Used by EduPageImporter (it relies on that class's upsert/pin/issue).
 */
trait ImportsAscStudents
{
    /**
     * @param  array<string, mixed>  $data
     */
    private function importAscStudents(Tenant $tenant, array $data, AcademicYear $year): void
    {
        if (! is_array($data['students'] ?? null) || ! is_array($data['lessons'] ?? null)) {
            throw new InvalidArgumentException('ფაილს მოსწავლეების („students“) ცხრილი აკლია.');
        }

        $this->counts['source_students'] = count($data['students']);
        $startsOn = Carbon::parse($year->starts_on->toDateString(), 'Asia/Tbilisi');
        $enrollments = app(EnrollmentHistory::class);
        $studentIds = [];

        foreach ($data['students'] as $row) {
            $id = (string) ($row['id'] ?? '');
            [$first, $last] = $this->ascName((string) ($row['name'] ?? ''));
            $classMap = $this->findMap($tenant, 'class', $year->id, (string) ($row['classid'] ?? ''));
            $class = $classMap === null ? null : SchoolClass::query()->where('tenant_id', $tenant->id)->find($classMap->target_id);

            if ($id === '' || $first === '') {
                $this->issue(null, "student:{$id}", 'validation', 'name', 'მოსწავლის სახელი ცარიელია.');

                continue;
            }

            if ($class === null) {
                $this->issue(null, "student:{$id}", 'unresolved_reference', 'class', 'მოსწავლის კლასი ჯერ იმპორტირებული არ არის — ჯერ asc-timetable.');

                continue;
            }

            $values = ['first_name' => $first, 'last_name' => $last, 'school_class_id' => $class->id, 'is_active' => true];
            $existingMap = $this->findMap($tenant, 'student', 0, $id);
            $before = $existingMap === null ? null : Student::query()->where('tenant_id', $tenant->id)->find($existingMap->target_id);
            $previousClass = $before?->school_class_id;

            $this->counts['rows']++;
            $student = $this->upsert(
                tenant: $tenant,
                entity: 'student',
                scopeId: 0,
                key: $id,
                row: 0,
                values: $values,
                model: Student::class,
                sameNamed: fn () => Student::query()->where('tenant_id', $tenant->id)->where('school_class_id', $class->id)
                    ->where('first_name', $first)->where('last_name', $last)->exists(),
                create: fn () => $this->createFor($tenant, new Student($values)),
            );
            $this->counts["student_{$this->lastOutcome}"] = ($this->counts["student_{$this->lastOutcome}"] ?? 0) + 1;

            if (! $student instanceof Student) {
                continue;
            }

            $studentIds[$id] = $student->id;

            if ($this->lastOutcome === 'created') {
                $enrollments->open($student, $class, $startsOn);
            } elseif ($this->lastOutcome === 'updated' && $previousClass !== null && $previousClass !== $class->id) {
                $enrollments->transfer($student, $class, $startsOn);
            }
        }

        // Teaching groups: a group lesson lists the pupils it is for.
        $members = 0;

        foreach ($data['lessons'] as $lesson) {
            foreach (array_map('strval', $lesson['groupids'] ?? []) as $groupSourceId) {
                $groupMap = $this->findMap($tenant, 'group', $year->id, $groupSourceId);

                if ($groupMap === null) {
                    continue; // the whole class, or a group no lesson uses
                }

                foreach (array_map('strval', $lesson['studentids'] ?? []) as $studentSourceId) {
                    if (! isset($studentIds[$studentSourceId])) {
                        continue;
                    }

                    $members += DB::table('student_group_members')->insertOrIgnore([
                        'tenant_id' => $tenant->id,
                        'student_group_id' => $groupMap->target_id,
                        'student_id' => $studentIds[$studentSourceId],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        $this->counts['group_memberships_added'] = $members;
    }

    /**
     * EduPage keeps one name field, given name first ("გიორგი ბერიძე").
     *
     * @return array{0: string, 1: string}
     */
    private function ascName(string $name): array
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];

        if (count($parts) < 2) {
            return [trim($name), ''];
        }

        return [array_shift($parts), implode(' ', $parts)];
    }
}
