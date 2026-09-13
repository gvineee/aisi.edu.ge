<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Academics\Models\Student;
use App\Domain\Academics\Models\TeacherAssignment;
use App\Domain\Learning\Actions\CreateAssignment;
use App\Domain\Learning\Actions\GradeSubmission;
use App\Domain\Learning\Actions\PublishAssignment;
use App\Domain\Learning\Actions\UnpublishAssignment;
use App\Domain\Learning\AssignmentFileStorage;
use App\Domain\Learning\Models\Assignment;
use App\Domain\Learning\Models\AssignmentSubmission;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\GradeSubmissionRequest;
use App\Http\Requests\Portal\StoreAssignmentRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "დავალებები" teacher workspace. A teacher only ever creates, publishes or
 * grades assignments on their own `teacher_assignment` rows (docs/02
 * critical check #3) — never every class in the school — mirroring
 * PortfolioController's/AttendanceController's ownership-check pattern.
 */
class AssignmentController extends Controller
{
    public function index(Request $request, CurrentTenant $currentTenant): Response
    {
        $tenant = $currentTenant->get();
        $user = $request->user();

        abort_unless(TenantMembership::userHasActiveRole($tenant->id, $user->id, TenantMembership::ROLE_TEACHER), 403);

        $teacherAssignments = TeacherAssignment::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->with('schoolClass')
            ->get();

        $assignments = Assignment::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('teacher_assignment_id', $teacherAssignments->pluck('id'))
            ->with('teacherAssignment.schoolClass')
            ->latest('updated_at')
            ->get();

        return Inertia::render('portal/assignments/index', [
            'teacherAssignments' => $teacherAssignments->map(fn (TeacherAssignment $teacherAssignment) => [
                'id' => $teacherAssignment->id,
                'label' => trim(($teacherAssignment->schoolClass->name ?? '').($teacherAssignment->subject ? " · {$teacherAssignment->subject}" : '')),
            ])->values(),
            'assignments' => $assignments->map(fn (Assignment $assignment) => $this->formatSummary($assignment))->values(),
        ]);
    }

    public function store(StoreAssignmentRequest $request, CurrentTenant $currentTenant, CreateAssignment $createAssignment): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $user = $request->user();

        $teacherAssignment = TeacherAssignment::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $request->integer('teacher_assignment_id'))
            ->first();

        abort_unless($teacherAssignment !== null && $teacherAssignment->user_id === $user->id, 403);

        $dueAt = $request->string('due_at')->isNotEmpty()
            ? Carbon::parse((string) $request->string('due_at'))
            : null;

        $assignment = $createAssignment->handle(
            tenantId: $tenant->id,
            teacherAssignment: $teacherAssignment,
            creator: $user,
            title: $request->string('title')->toString(),
            description: $request->string('description')->toString() ?: null,
            dueAt: $dueAt,
            maxScore: $request->integer('max_score') ?: null,
        );

        return redirect()->route('assignments.index')->with('assignmentId', $assignment->id);
    }

    public function publish(Request $request, CurrentTenant $currentTenant, Assignment $assignment, PublishAssignment $publishAssignment): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($assignment->tenant_id === $tenant->id, 404);
        $this->authorizeOwner($request, $assignment);

        $publishAssignment->handle($assignment, $request->user()->id);

        return back();
    }

    public function unpublish(Request $request, CurrentTenant $currentTenant, Assignment $assignment, UnpublishAssignment $unpublishAssignment): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($assignment->tenant_id === $tenant->id, 404);
        $this->authorizeOwner($request, $assignment);

        $unpublishAssignment->handle($assignment, $request->user()->id);

        return back();
    }

    public function submissions(Request $request, CurrentTenant $currentTenant, Assignment $assignment): Response
    {
        $tenant = $currentTenant->get();
        abort_unless($assignment->tenant_id === $tenant->id, 404);
        $this->authorizeOwner($request, $assignment);

        $assignment->load('teacherAssignment.schoolClass');

        $students = Student::query()
            ->where('tenant_id', $tenant->id)
            ->where('school_class_id', $assignment->teacherAssignment->school_class_id)
            ->where('is_active', true)
            ->orderBy('last_name')
            ->get();

        $submissions = AssignmentSubmission::query()
            ->where('tenant_id', $tenant->id)
            ->where('assignment_id', $assignment->id)
            ->get()
            ->keyBy('student_id');

        return Inertia::render('portal/assignments/submissions', [
            'assignment' => $this->formatSummary($assignment),
            'roster' => $students->map(function (Student $student) use ($submissions, $assignment) {
                $submission = $submissions->get($student->id);

                return [
                    'studentId' => $student->id,
                    'studentName' => $student->fullName(),
                    'submissionId' => $submission?->id,
                    'status' => $submission === null ? AssignmentSubmission::STATUS_NOT_SUBMITTED : $submission->status,
                    'isLate' => $submission !== null && $submission->is_late,
                    'submittedAt' => $submission?->submitted_at?->toIso8601String(),
                    'textResponse' => $submission?->text_response,
                    'downloadUrl' => $submission !== null && $submission->file_path !== null
                        ? app(AssignmentFileStorage::class)->signedDownloadUrl($assignment->id, $submission->id)
                        : null,
                    'score' => $submission?->score,
                    'feedback' => $submission?->feedback,
                ];
            })->values(),
        ]);
    }

    public function grade(GradeSubmissionRequest $request, CurrentTenant $currentTenant, Assignment $assignment, AssignmentSubmission $submission, GradeSubmission $gradeSubmission): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($assignment->tenant_id === $tenant->id, 404);
        abort_unless($submission->tenant_id === $tenant->id && $submission->assignment_id === $assignment->id, 404);
        $this->authorizeOwner($request, $assignment);

        $gradeSubmission->handle(
            submission: $submission,
            grader: $request->user(),
            score: $request->integer('score'),
            feedback: $request->string('feedback')->toString() ?: null,
        );

        return back();
    }

    public function downloadSubmission(Request $request, CurrentTenant $currentTenant, Assignment $assignment, AssignmentSubmission $submission): StreamedResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($assignment->tenant_id === $tenant->id, 404);
        abort_unless($submission->tenant_id === $tenant->id && $submission->assignment_id === $assignment->id, 404);
        abort_if($submission->file_path === null, 404);

        $assignment->loadMissing('teacherAssignment');
        $submission->loadMissing('student');
        $user = $request->user();
        $isTeacher = $assignment->teacherAssignment->user_id === $user->id;
        $isOwner = $submission->student?->user_id === $user->id;

        abort_unless($isTeacher || $isOwner, 403);

        return Storage::disk('local')->download($submission->file_path);
    }

    private function authorizeOwner(Request $request, Assignment $assignment): void
    {
        $assignment->loadMissing('teacherAssignment');
        abort_unless($assignment->teacherAssignment->user_id === $request->user()->id, 403);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatSummary(Assignment $assignment): array
    {
        return [
            'id' => $assignment->id,
            'title' => $assignment->title,
            'description' => $assignment->description,
            'className' => $assignment->teacherAssignment->schoolClass->name ?? null,
            'subject' => $assignment->teacherAssignment->subject,
            'status' => $assignment->status,
            'dueAt' => $assignment->due_at?->toIso8601String(),
            'maxScore' => $assignment->max_score,
            'submissionsCount' => $assignment->submissions()->count(),
            'updatedAt' => $assignment->updated_at?->toIso8601String(),
        ];
    }
}
