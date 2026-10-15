<?php

namespace App\Domain\Academics\Actions;

use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\TeacherAssignment;
use App\Domain\Governance\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds and removes a teacher's class/subject assignments after the first
 * invitation. Every teacher access check goes through these rows, so each
 * change is audit-logged.
 *
 * Assignments, assessments, report-card subjects and competency
 * observations reference teacher_assignments with ON DELETE CASCADE, so
 * deleting a used assignment would silently destroy homework and grades.
 * Removal is therefore only allowed while nothing depends on the row.
 */
class ManageTeacherAssignments
{
    /**
     * @var array<string, string> dependent table => label used in the error message
     */
    private const DEPENDENTS = [
        'assignments' => 'დავალებები',
        'assessments' => 'შეფასებები',
        'report_card_subjects' => 'ტაბელი',
        'student_competency_observations' => 'კომპეტენციების დაკვირვებები',
    ];

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function add(SchoolClass $schoolClass, User $teacher, ?string $subject, User $actor): TeacherAssignment
    {
        $subject = $subject !== null && trim($subject) !== '' ? trim($subject) : null;

        $exists = TeacherAssignment::query()
            ->where('tenant_id', $schoolClass->tenant_id)
            ->where('user_id', $teacher->id)
            ->where('school_class_id', $schoolClass->id)
            ->where('subject', $subject)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['school_class_id' => 'ეს კლასი და საგანი მასწავლებელს უკვე მინიჭებული აქვს.']);
        }

        $assignment = new TeacherAssignment([
            'user_id' => $teacher->id,
            'school_class_id' => $schoolClass->id,
            'subject' => $subject,
        ]);
        $assignment->tenant_id = $schoolClass->tenant_id;
        $assignment->save();

        $this->auditLogger->record($schoolClass->tenant_id, 'teacher_assignment.created', $assignment, $actor->id, [
            'teacher_user_id' => $teacher->id,
            'school_class_id' => $schoolClass->id,
            'subject' => $subject,
        ]);

        return $assignment;
    }

    public function remove(TeacherAssignment $assignment, User $actor): void
    {
        $used = [];

        foreach (self::DEPENDENTS as $table => $label) {
            if (DB::table($table)->where('tenant_id', $assignment->tenant_id)->where('teacher_assignment_id', $assignment->id)->exists()) {
                $used[] = $label;
            }
        }

        if ($used !== []) {
            throw ValidationException::withMessages([
                'assignment' => 'მინიჭებას უკვე აქვს დაკავშირებული ჩანაწერები ('.implode(', ', $used).') — წაშლა შეუძლებელია, რომ მონაცემები არ დაიკარგოს.',
            ]);
        }

        $meta = [
            'teacher_user_id' => $assignment->user_id,
            'school_class_id' => $assignment->school_class_id,
            'subject' => $assignment->subject,
        ];

        $assignment->delete();

        $this->auditLogger->record($assignment->tenant_id, 'teacher_assignment.removed', $assignment, $actor->id, $meta);
    }
}
