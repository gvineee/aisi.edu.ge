<?php

namespace App\Domain\Timetable\Actions;

use App\Domain\Governance\AuditLogger;
use App\Domain\Timetable\Models\Lesson;
use App\Models\User;

/**
 * Creates a `lessons` row (a recurring weekly slot). Conflict checking
 * (teacher/room/class double-booking) already happened in
 * StoreLessonRequest before this action is ever called — this is just the
 * write + audit log, pulled out of the controller so it follows the same
 * "controller/Form Request + domain action" split as every other write path
 * (CLAUDE.md's architecture rule), and so schedule changes are audited like
 * every other significant change (invariant #7), which the original inline
 * controller write never did.
 */
class CreateLesson
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array<string, mixed>  $data  already-validated StoreLessonRequest fields
     */
    public function handle(int $tenantId, User $actor, array $data): Lesson
    {
        $data['created_by'] = $actor->id;

        $lesson = new Lesson($data);
        $lesson->tenant_id = $tenantId;
        $lesson->save();

        $this->auditLogger->record($tenantId, 'lesson.created', $lesson, $actor->id, [
            'school_class_id' => $lesson->school_class_id,
            'subject_id' => $lesson->subject_id,
            'teacher_id' => $lesson->teacher_id,
            'day_of_week' => $lesson->day_of_week,
            'status' => $lesson->status,
        ]);

        return $lesson;
    }
}
