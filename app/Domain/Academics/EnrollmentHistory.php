<?php

namespace App\Domain\Academics;

use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\Student;
use App\Domain\Academics\Models\StudentEnrollment;
use Illuminate\Support\Carbon;

/**
 * Keeps `student_enrollments` — the dated history of which class a pupil
 * was in — in step with roster changes, the way a student information
 * system keeps enrollment spans: joining opens a span, a transfer closes
 * it and opens the next, leaving closes it. `students.school_class_id`
 * stays the cached current class.
 */
class EnrollmentHistory
{
    public function open(Student $student, SchoolClass $schoolClass, ?Carbon $on = null): StudentEnrollment
    {
        $enrollment = new StudentEnrollment([
            'student_id' => $student->id,
            'academic_year_id' => $schoolClass->academic_year_id,
            'school_class_id' => $schoolClass->id,
            'starts_on' => $this->day($on),
            'status' => StudentEnrollment::STATUS_ACTIVE,
        ]);
        $enrollment->tenant_id = $student->tenant_id;
        $enrollment->save();

        return $enrollment;
    }

    public function close(Student $student, string $status, ?Carbon $on = null): void
    {
        StudentEnrollment::query()
            ->where('tenant_id', $student->tenant_id)
            ->where('student_id', $student->id)
            ->where('status', StudentEnrollment::STATUS_ACTIVE)
            ->update(['status' => $status, 'ends_on' => $this->day($on), 'updated_at' => now()]);
    }

    public function transfer(Student $student, SchoolClass $to, ?Carbon $on = null): void
    {
        $this->close($student, StudentEnrollment::STATUS_TRANSFERRED, $on);
        $this->open($student, $to, $on);
    }

    /** Calendar days are the school's own (Asia/Tbilisi), not UTC. */
    private function day(?Carbon $on): string
    {
        return ($on ?? Carbon::now('Asia/Tbilisi'))->toDateString();
    }
}
