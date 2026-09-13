<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Academics\Models\Student;
use App\Domain\Learning\Actions\SubmitAssignment;
use App\Domain\Learning\Models\Assignment;
use App\Domain\Learning\Models\AssignmentSubmission;
use App\Domain\Tenancy\CurrentTenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\SubmitAssignmentRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "ჩემი დავალებები" — a student only ever sees published assignments for
 * their own class, and can only ever submit for their own enrollment
 * (docs/02 critical check: a student cannot submit for another student).
 * The submitting student is always resolved from the authenticated user's
 * own Student record, never from a client-supplied id.
 */
class MyAssignmentsController extends Controller
{
    public function index(Request $request, CurrentTenant $currentTenant): Response
    {
        $tenant = $currentTenant->get();
        $student = $this->currentStudent($request, $tenant->id);

        abort_unless($student !== null, 403);

        $assignments = Assignment::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', Assignment::STATUS_PUBLISHED)
            ->whereHas('teacherAssignment', fn ($query) => $query->where('school_class_id', $student->school_class_id))
            ->with('teacherAssignment')
            ->latest('due_at')
            ->get();

        $submissions = AssignmentSubmission::query()
            ->where('tenant_id', $tenant->id)
            ->where('student_id', $student->id)
            ->get()
            ->keyBy('assignment_id');

        return Inertia::render('portal/my-assignments/index', [
            'assignments' => $assignments->map(function (Assignment $assignment) use ($submissions) {
                $submission = $submissions->get($assignment->id);

                return [
                    'id' => $assignment->id,
                    'title' => $assignment->title,
                    'description' => $assignment->description,
                    'subject' => $assignment->teacherAssignment->subject,
                    'dueAt' => $assignment->due_at?->toIso8601String(),
                    'maxScore' => $assignment->max_score,
                    'status' => $submission === null ? AssignmentSubmission::STATUS_NOT_SUBMITTED : $submission->status,
                    'isLate' => $submission !== null && $submission->is_late,
                    'score' => $submission?->score,
                    'feedback' => $submission?->feedback,
                    'canSubmit' => $submission?->status !== AssignmentSubmission::STATUS_GRADED,
                ];
            })->values(),
        ]);
    }

    public function submit(SubmitAssignmentRequest $request, CurrentTenant $currentTenant, Assignment $assignment, SubmitAssignment $submitAssignment): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($assignment->tenant_id === $tenant->id, 404);

        $student = $this->currentStudent($request, $tenant->id);
        abort_unless($student !== null, 403);

        $assignment->loadMissing('teacherAssignment');
        abort_unless($assignment->teacherAssignment->school_class_id === $student->school_class_id, 404);
        abort_unless($assignment->isPublished(), 404);

        $submitAssignment->handle(
            assignment: $assignment,
            student: $student,
            file: $request->file('file'),
            textResponse: $request->string('text_response')->toString() ?: null,
        );

        return redirect()->route('my-assignments.index');
    }

    private function currentStudent(Request $request, int $tenantId): ?Student
    {
        return Student::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $request->user()->id)
            ->where('is_active', true)
            ->first();
    }
}
