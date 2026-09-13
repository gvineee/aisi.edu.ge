<?php

namespace App\Domain\Learning\Actions;

use App\Domain\Academics\Models\Student;
use App\Domain\Learning\AssignmentFileStorage;
use App\Domain\Learning\Models\Assignment;
use App\Domain\Learning\Models\AssignmentSubmission;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Late-submission policy varies by school and must not be invented here
 * (repo brief) — being past `due_at` only ever sets the `is_late` flag; it
 * never blocks the submission itself. Idempotent per (assignment, student):
 * a second call before grading updates the same row rather than violating
 * the unique constraint.
 */
class SubmitAssignment
{
    public function __construct(private readonly AssignmentFileStorage $storage) {}

    public function handle(Assignment $assignment, Student $student, ?UploadedFile $file, ?string $textResponse): AssignmentSubmission
    {
        if (! $assignment->isPublished()) {
            throw new RuntimeException("Assignment {$assignment->id} is not published.");
        }

        $submission = AssignmentSubmission::query()
            ->where('assignment_id', $assignment->id)
            ->where('student_id', $student->id)
            ->first();

        if ($submission !== null && $submission->status === AssignmentSubmission::STATUS_GRADED) {
            throw new RuntimeException("Submission for assignment {$assignment->id} by student {$student->id} has already been graded.");
        }

        $submission ??= new AssignmentSubmission;
        $submission->tenant_id = $assignment->tenant_id;
        $submission->assignment_id = $assignment->id;
        $submission->student_id = $student->id;

        if ($file !== null) {
            $submission->file_path = $this->storage->store($file, $assignment->tenant_id, $assignment->id, $student->id);
        }

        if ($textResponse !== null) {
            $submission->text_response = $textResponse;
        }

        $submission->submitted_at = Carbon::now();
        $submission->is_late = $assignment->due_at !== null && Carbon::now()->greaterThan($assignment->due_at);
        $submission->status = AssignmentSubmission::STATUS_SUBMITTED;
        $submission->save();

        return $submission;
    }
}
