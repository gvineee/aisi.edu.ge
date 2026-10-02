<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\Student;
use App\Domain\Academics\Models\TeacherAssignment;
use App\Domain\Assessment\Models\Assessment;
use App\Domain\Assessment\Models\ReportCard;
use App\Domain\Assessment\Models\ReportCardSubject;
use App\Domain\Communications\Models\ConversationParticipant;
use App\Domain\Learning\Models\Assignment;
use App\Domain\Learning\Models\AssignmentSubmission;
use App\Domain\Learning\Models\AttendanceRecord;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Timetable\Models\Lesson;
use App\Domain\Timetable\Models\SubstitutionAssignment;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "მასწავლებლის სივრცე" (`/portal/teacher`, CLAUDE-PLATFORM-MODULES §9):
 * today's own and substitute lessons with attendance completion, work to
 * grade, gradebook drafts, report card lines, unread messages and the
 * teacher's classes — only ever the teacher's own assignments. A lesson's
 * detail is open to its teacher, or to its substitute on the covered day.
 */
class TeacherWorkspaceController extends Controller
{
    public function index(Request $request, CurrentTenant $currentTenant): Response
    {
        $tenant = $currentTenant->get();
        $user = $request->user();
        abort_unless(TenantMembership::userHasActiveRole($tenant->id, $user->id, TenantMembership::ROLE_TEACHER), 403);

        $now = Carbon::now($tenant->timezone);
        $today = $now->toDateString();

        $own = Lesson::query()->where('tenant_id', $tenant->id)->where('teacher_id', $user->id)->where('status', Lesson::STATUS_PUBLISHED)
            ->where('day_of_week', $now->dayOfWeekIso)->with('subject', 'schoolClass', 'room')->orderBy('starts_at')->get();
        $covering = SubstitutionAssignment::query()->where('tenant_id', $tenant->id)->where('substitute_teacher_id', $user->id)
            ->where('status', SubstitutionAssignment::STATUS_ASSIGNED)->whereDate('date', $today)->with('lesson.subject', 'lesson.schoolClass', 'lesson.room')->get();

        $lessonRows = $own->toBase()->map(fn (Lesson $lesson) => $this->lessonRow($tenant->id, $lesson, $today, false))
            ->merge($covering->map(fn (SubstitutionAssignment $s) => $this->lessonRow($tenant->id, $s->lesson, $today, true)))
            ->sortBy('startsAt')->values();

        $teacherAssignments = TeacherAssignment::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->with('schoolClass')->get();
        $taIds = $teacherAssignments->pluck('id');

        return Inertia::render('portal/teacher/workspace', [
            'date' => $today,
            'lessons' => $lessonRows,
            'toGrade' => AssignmentSubmission::query()->where('tenant_id', $tenant->id)->where('status', AssignmentSubmission::STATUS_SUBMITTED)
                ->whereIn('assignment_id', Assignment::query()->where('tenant_id', $tenant->id)->whereIn('teacher_assignment_id', $taIds)->select('id'))->count(),
            'draftAssessments' => Assessment::query()->where('tenant_id', $tenant->id)->whereIn('teacher_assignment_id', $taIds)->where('status', Assessment::STATUS_DRAFT)->count(),
            'reportCardLines' => ReportCardSubject::query()->where('tenant_id', $tenant->id)->whereIn('teacher_assignment_id', $taIds)->whereNull('completed_at')
                ->whereHas('reportCard', fn ($query) => $query->where('status', ReportCard::STATUS_DRAFT))->count(),
            'unreadConversations' => $this->unreadCount($tenant->id, $user),
            'classes' => $teacherAssignments->map(fn (TeacherAssignment $ta) => [
                'id' => $ta->id,
                'className' => $ta->schoolClass->name ?? null,
                'subject' => $ta->subject,
                'students' => $ta->schoolClass === null ? 0 : Student::query()->where('tenant_id', $tenant->id)->where('school_class_id', $ta->school_class_id)->where('is_active', true)->count(),
                'isHomeroom' => $ta->schoolClass !== null && $ta->schoolClass->homeroom_teacher_id === $user->id,
            ])->values(),
            'homeroomClasses' => SchoolClass::query()->where('tenant_id', $tenant->id)->where('homeroom_teacher_id', $user->id)->pluck('name'),
        ]);
    }

    public function lesson(Request $request, CurrentTenant $currentTenant, Lesson $lesson): Response
    {
        $tenant = $currentTenant->get();
        abort_unless($lesson->tenant_id === $tenant->id, 404);
        $user = $request->user();
        $today = Carbon::now($tenant->timezone)->toDateString();

        $isOwner = $lesson->teacher_id === $user->id;
        $isSubstituteToday = SubstitutionAssignment::query()->where('tenant_id', $tenant->id)->where('lesson_id', $lesson->id)
            ->where('substitute_teacher_id', $user->id)->where('status', SubstitutionAssignment::STATUS_ASSIGNED)->whereDate('date', $today)->exists();
        abort_unless(TenantMembership::userHasActiveRole($tenant->id, $user->id, TenantMembership::ROLE_TEACHER) && ($isOwner || $isSubstituteToday), 403);

        $lesson->load('subject', 'schoolClass', 'room');
        $attendance = AttendanceRecord::query()->where('tenant_id', $tenant->id)->where('lesson_id', $lesson->id)->whereDate('occurred_on', $today)->get()->keyBy('student_id');

        // The substitute sees the roster for this lesson today, not the
        // class's assignments or grades.
        $assignments = $isOwner
            ? Assignment::query()->where('tenant_id', $tenant->id)
                ->whereIn('teacher_assignment_id', TeacherAssignment::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('school_class_id', $lesson->school_class_id)->select('id'))
                ->latest('id')->limit(10)->get()->map(fn (Assignment $a) => ['id' => $a->id, 'title' => $a->title, 'status' => $a->status, 'dueAt' => $a->due_at?->toIso8601String()])->values()
            : [];

        return Inertia::render('portal/teacher/lesson', [
            'lesson' => [
                'id' => $lesson->id,
                'subject' => $lesson->subject->name ?? null,
                'className' => $lesson->schoolClass->name ?? null,
                'startsAt' => substr($lesson->starts_at, 0, 5),
                'endsAt' => substr($lesson->ends_at, 0, 5),
                'room' => $lesson->room?->name,
                'onlineUrl' => $lesson->online_url,
            ],
            'isSubstitute' => ! $isOwner,
            'date' => $today,
            'roster' => Student::query()->where('tenant_id', $tenant->id)->where('school_class_id', $lesson->school_class_id)->where('is_active', true)
                ->orderBy('last_name')->get()->map(fn (Student $s) => ['id' => $s->id, 'name' => $s->fullName(), 'attendance' => $attendance->get($s->id)?->status])->values(),
            'assignments' => $assignments,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function lessonRow(int $tenantId, Lesson $lesson, string $today, bool $substitute): array
    {
        $marked = AttendanceRecord::query()->where('tenant_id', $tenantId)->where('lesson_id', $lesson->id)->whereDate('occurred_on', $today)->count();
        $roster = Student::query()->where('tenant_id', $tenantId)->where('school_class_id', $lesson->school_class_id)->where('is_active', true)->count();

        return [
            'id' => $lesson->id,
            'subject' => $lesson->subject->name ?? null,
            'className' => $lesson->schoolClass->name ?? null,
            'startsAt' => substr($lesson->starts_at, 0, 5),
            'endsAt' => substr($lesson->ends_at, 0, 5),
            'room' => $lesson->room?->name,
            'isSubstitute' => $substitute,
            'attendanceMarked' => $marked,
            'roster' => $roster,
        ];
    }

    private function unreadCount(int $tenantId, User $user): int
    {
        return ConversationParticipant::query()->where('tenant_id', $tenantId)->where('user_id', $user->id)->with('conversation.messages')->get()
            ->filter(function (ConversationParticipant $p) {
                if ($p->conversation === null) {
                    return false;
                }

                return $p->last_read_message_id === null
                    ? $p->conversation->messages->isNotEmpty()
                    : $p->conversation->messages->where('id', '>', $p->last_read_message_id)->isNotEmpty();
            })->count();
    }
}
