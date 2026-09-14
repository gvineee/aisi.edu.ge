<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Academics\Models\Student;
use App\Domain\Academics\Models\TeacherAssignment;
use App\Domain\Content\Models\Post;
use App\Domain\Documents\Actions\ListPendingApprovalRequests;
use App\Domain\Documents\Models\ApprovalRequest;
use App\Domain\Portal\Actions\BuildDailyActionFeed;
use App\Domain\Portfolio\Models\PortfolioItem;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\PortalContext;
use App\Domain\Timetable\Actions\ResolveDailyLessons;
use App\Domain\Timetable\Models\Lesson;
use App\Domain\Timetable\Models\SubstitutionAssignment;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The authenticated "today" screen. Which data (and which Inertia page) a
 * user sees depends entirely on their server-resolved active role
 * (PortalContext) for the current tenant — never on anything the client
 * claims. A user with no active role sees an honest empty state, not
 * fabricated content.
 */
class DashboardController extends Controller
{
    public function __invoke(
        Request $request,
        CurrentTenant $currentTenant,
        PortalContext $portalContext,
        ResolveDailyLessons $resolveDailyLessons,
        BuildDailyActionFeed $buildDailyActionFeed,
    ): Response {
        $tenant = $currentTenant->get();
        $user = $request->user();
        $today = Carbon::now($tenant->timezone);

        $activeRole = $portalContext->resolveActiveRole($request, $tenant->id, $user->id);

        if ($activeRole === null) {
            return Inertia::render('portal/no-role', ['name' => $user->name]);
        }

        $actionItems = $buildDailyActionFeed->forUser($tenant->id, $user, $activeRole);
        $recentNews = $this->recentNews($tenant->id);

        return match ($activeRole) {
            TenantMembership::ROLE_GUARDIAN => $this->renderGuardian($tenant, $user, $today, $resolveDailyLessons, $actionItems, $recentNews),
            TenantMembership::ROLE_TEACHER => $this->renderTeacher($tenant, $user, $today, $resolveDailyLessons, $actionItems, $recentNews),
            TenantMembership::ROLE_STUDENT => $this->renderStudent($tenant, $user, $today, $resolveDailyLessons, $actionItems, $recentNews),
            TenantMembership::ROLE_DIRECTOR => $this->renderDirector($tenant, $actionItems, $recentNews),
            TenantMembership::ROLE_ADMIN => $this->renderAdmin($tenant, $actionItems, $recentNews),
            default => Inertia::render('portal/no-role', ['name' => $user->name]),
        };
    }

    /**
     * Real published-post teasers for the "today" screen's school-news
     * panel (design/app/AisiConcept.tsx's "სკოლის ამბები") — never invented
     * announcements; an empty array renders an honest empty state.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recentNews(int $tenantId): array
    {
        return Post::query()
            ->where('tenant_id', $tenantId)
            ->where('status', Post::STATUS_PUBLISHED)
            ->whereNotNull('published_at')
            ->latest('published_at')
            ->take(2)
            ->get()
            ->map(fn (Post $post) => [
                'slug' => $post->slug,
                'title' => $post->title,
                'excerpt' => $post->excerpt,
                'publishedAt' => $post->published_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $actionItems
     * @param  array<int, array<string, mixed>>  $recentNews
     */
    private function renderGuardian(Tenant $tenant, User $user, Carbon $today, ResolveDailyLessons $resolveDailyLessons, array $actionItems, array $recentNews): Response
    {
        $links = GuardianLink::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->with(['student.schoolClass'])
            ->get()
            ->filter(fn (GuardianLink $link) => $link->student !== null && $link->student->is_active);

        $children = [];

        foreach ($links as $link) {
            $todaySchedule = [];

            if ($link->can_view_academic && $link->student->school_class_id !== null) {
                $lessons = $resolveDailyLessons->forSchoolClass($tenant->id, $link->student->school_class_id, $today);
                $todaySchedule = $lessons->map(fn (array $entry) => $this->formatScheduleEntry($entry))->values()->all();
            }

            $children[] = [
                'id' => $link->student->id,
                'name' => $link->student->fullName(),
                'className' => $link->student->schoolClass?->name,
                'permissions' => [
                    'academic' => $link->can_view_academic,
                    'financial' => $link->can_view_financial,
                    'pickup' => $link->can_pickup,
                    'notifications' => $link->can_receive_notifications,
                ],
                'todaySchedule' => $todaySchedule,
            ];
        }

        return Inertia::render('portal/parent-dashboard', [
            'children' => $children,
            'actionItems' => $actionItems,
            'recentNews' => $recentNews,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $actionItems
     * @param  array<int, array<string, mixed>>  $recentNews
     */
    private function renderTeacher(Tenant $tenant, User $user, Carbon $today, ResolveDailyLessons $resolveDailyLessons, array $actionItems, array $recentNews): Response
    {
        $lessons = $resolveDailyLessons->forTeacher($tenant->id, $user->id, $today);

        $classIds = TeacherAssignment::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->pluck('school_class_id');

        $portfolioReviewCount = PortfolioItem::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', PortfolioItem::STATUS_SUBMITTED)
            ->whereHas('student', fn ($query) => $query->whereIn('school_class_id', $classIds))
            ->count();

        // Substitution coverage (docs/02 "teacher workspace" — this teacher
        // stepping in for someone else today, not their own schedule above).
        $substitutions = SubstitutionAssignment::query()
            ->where('tenant_id', $tenant->id)
            ->where('substitute_teacher_id', $user->id)
            ->where('status', SubstitutionAssignment::STATUS_ASSIGNED)
            ->whereDate('date', $today->toDateString())
            ->with(['lesson.subject', 'lesson.schoolClass', 'lesson.room', 'absentTeacher'])
            ->get();

        return Inertia::render('portal/teacher-dashboard', [
            'date' => $today->toDateString(),
            'lessons' => $lessons->map(fn (array $entry) => [
                ...$this->formatScheduleEntry($entry),
                'lessonId' => $entry['lesson']->id,
                'className' => $entry['lesson']->schoolClass->name,
            ])->values()->all(),
            'portfolioReviewCount' => $portfolioReviewCount,
            'substitutions' => $substitutions->map(fn (SubstitutionAssignment $assignment) => [
                'lessonId' => $assignment->lesson_id,
                'subject' => $assignment->lesson->subject->name,
                'className' => $assignment->lesson->schoolClass->name,
                'startsAt' => substr($assignment->lesson->starts_at, 0, 5),
                'endsAt' => substr($assignment->lesson->ends_at, 0, 5),
                'roomName' => $assignment->lesson->room?->name,
                'absentTeacherName' => $assignment->absentTeacher->name,
            ])->values()->all(),
            'actionItems' => $actionItems,
            'recentNews' => $recentNews,
        ]);
    }

    /**
     * A student login is only meaningful once their Student record is
     * linked via students.user_id (most Student rows today have no linked
     * account) — an active "student" membership without that link is a real,
     * honest gap, not something to paper over with invented content.
     *
     * @param  array<int, array<string, mixed>>  $actionItems
     * @param  array<int, array<string, mixed>>  $recentNews
     */
    private function renderStudent(Tenant $tenant, User $user, Carbon $today, ResolveDailyLessons $resolveDailyLessons, array $actionItems, array $recentNews): Response
    {
        $student = Student::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->with('schoolClass')
            ->first();

        if ($student === null) {
            return Inertia::render('portal/student-dashboard', [
                'linked' => false,
                'userName' => $user->name,
                'className' => null,
                'todaySchedule' => [],
                'actionItems' => $actionItems,
                'recentNews' => $recentNews,
            ]);
        }

        $todaySchedule = [];

        if ($student->school_class_id !== null) {
            $lessons = $resolveDailyLessons->forSchoolClass($tenant->id, $student->school_class_id, $today);
            $todaySchedule = $lessons->map(fn (array $entry) => $this->formatScheduleEntry($entry))->values()->all();
        }

        return Inertia::render('portal/student-dashboard', [
            'linked' => true,
            'studentId' => $student->id,
            'userName' => $user->name,
            'className' => $student->schoolClass?->name,
            'todaySchedule' => $todaySchedule,
            'actionItems' => $actionItems,
            'recentNews' => $recentNews,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $actionItems
     * @param  array<int, array<string, mixed>>  $recentNews
     */
    private function renderDirector(Tenant $tenant, array $actionItems, array $recentNews): Response
    {
        $pending = app(ListPendingApprovalRequests::class)->handle($tenant->id);

        return Inertia::render('portal/director-dashboard', [
            'pendingCount' => $pending->count(),
            'preview' => $pending->take(3)->map(fn (ApprovalRequest $approvalRequest) => [
                'id' => $approvalRequest->id,
                'documentId' => $approvalRequest->documentVersion->document->id,
                'documentTitle' => $approvalRequest->documentVersion->document->title,
                'authorName' => $approvalRequest->documentVersion->author->name,
                'submittedAt' => $approvalRequest->created_at?->toIso8601String(),
            ])->values(),
            'actionItems' => $actionItems,
            'recentNews' => $recentNews,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $actionItems
     * @param  array<int, array<string, mixed>>  $recentNews
     */
    private function renderAdmin(Tenant $tenant, array $actionItems, array $recentNews): Response
    {
        $memberCount = $tenant->memberships()->where('is_active', true)->count();
        $pendingApprovals = app(ListPendingApprovalRequests::class)->handle($tenant->id)->count();

        return Inertia::render('portal/admin-dashboard', [
            'memberCount' => $memberCount,
            'pendingApprovals' => $pendingApprovals,
            'recentNews' => $recentNews,
            'actionItems' => $actionItems,
        ]);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function formatScheduleEntry(array $entry): array
    {
        /** @var Lesson $lesson */
        $lesson = $entry['lesson'];

        return [
            'subject' => $lesson->subject->name,
            'startsAt' => substr($lesson->starts_at, 0, 5),
            'endsAt' => substr($lesson->ends_at, 0, 5),
            'teacherName' => $entry['teacherName'],
            'roomName' => $entry['roomName'],
            'cancelled' => $entry['cancelled'],
        ];
    }
}
