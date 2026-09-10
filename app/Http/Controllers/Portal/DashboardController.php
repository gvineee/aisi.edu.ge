<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Academics\Models\Student;
use App\Domain\Documents\Actions\ListPendingApprovalRequests;
use App\Domain\Documents\Models\ApprovalRequest;
use App\Domain\Portal\Actions\BuildDailyActionFeed;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\PortalContext;
use App\Domain\Timetable\Actions\ResolveDailyLessons;
use App\Domain\Timetable\Models\Lesson;
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

        return match ($activeRole) {
            TenantMembership::ROLE_GUARDIAN => $this->renderGuardian($tenant, $user, $today, $resolveDailyLessons, $actionItems),
            TenantMembership::ROLE_TEACHER => $this->renderTeacher($tenant, $user, $today, $resolveDailyLessons, $actionItems),
            TenantMembership::ROLE_STUDENT => $this->renderStudent($tenant, $user, $today, $resolveDailyLessons, $actionItems),
            TenantMembership::ROLE_DIRECTOR => $this->renderDirector($tenant, $actionItems),
            TenantMembership::ROLE_ADMIN => $this->renderAdmin($tenant, $actionItems),
            default => Inertia::render('portal/no-role', ['name' => $user->name]),
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $actionItems
     */
    private function renderGuardian(Tenant $tenant, User $user, Carbon $today, ResolveDailyLessons $resolveDailyLessons, array $actionItems): Response
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
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $actionItems
     */
    private function renderTeacher(Tenant $tenant, User $user, Carbon $today, ResolveDailyLessons $resolveDailyLessons, array $actionItems): Response
    {
        $lessons = $resolveDailyLessons->forTeacher($tenant->id, $user->id, $today);

        return Inertia::render('portal/teacher-dashboard', [
            'date' => $today->toDateString(),
            'lessons' => $lessons->map(fn (array $entry) => [
                ...$this->formatScheduleEntry($entry),
                'lessonId' => $entry['lesson']->id,
                'className' => $entry['lesson']->schoolClass->name,
            ])->values()->all(),
            'actionItems' => $actionItems,
        ]);
    }

    /**
     * A student login is only meaningful once their Student record is
     * linked via students.user_id (most Student rows today have no linked
     * account) — an active "student" membership without that link is a real,
     * honest gap, not something to paper over with invented content.
     *
     * @param  array<int, array<string, mixed>>  $actionItems
     */
    private function renderStudent(Tenant $tenant, User $user, Carbon $today, ResolveDailyLessons $resolveDailyLessons, array $actionItems): Response
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
                'className' => null,
                'todaySchedule' => [],
                'actionItems' => $actionItems,
            ]);
        }

        $todaySchedule = [];

        if ($student->school_class_id !== null) {
            $lessons = $resolveDailyLessons->forSchoolClass($tenant->id, $student->school_class_id, $today);
            $todaySchedule = $lessons->map(fn (array $entry) => $this->formatScheduleEntry($entry))->values()->all();
        }

        return Inertia::render('portal/student-dashboard', [
            'linked' => true,
            'className' => $student->schoolClass?->name,
            'todaySchedule' => $todaySchedule,
            'actionItems' => $actionItems,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $actionItems
     */
    private function renderDirector(Tenant $tenant, array $actionItems): Response
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
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $actionItems
     */
    private function renderAdmin(Tenant $tenant, array $actionItems): Response
    {
        $memberCount = $tenant->memberships()->where('is_active', true)->count();
        $pendingApprovals = app(ListPendingApprovalRequests::class)->handle($tenant->id)->count();

        return Inertia::render('portal/admin-dashboard', [
            'memberCount' => $memberCount,
            'pendingApprovals' => $pendingApprovals,
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
