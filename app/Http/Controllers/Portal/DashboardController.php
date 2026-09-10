<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Timetable\Actions\ResolveDailyLessons;
use App\Domain\Timetable\Models\Lesson;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The authenticated "today" screen. Which data (and which Inertia page) a
 * user sees depends entirely on their server-resolved role for the current
 * tenant — never on anything the client claims. A user with no active
 * membership/role sees an honest empty state, not fabricated content.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, CurrentTenant $currentTenant, ResolveDailyLessons $resolveDailyLessons): Response
    {
        $tenant = $currentTenant->get();
        $user = $request->user();
        $today = Carbon::now($tenant->timezone);

        if (TenantMembership::userHasActiveRole($tenant->id, $user->id, TenantMembership::ROLE_GUARDIAN)) {
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
            ]);
        }

        if (TenantMembership::userHasActiveRole($tenant->id, $user->id, TenantMembership::ROLE_TEACHER)) {
            $lessons = $resolveDailyLessons->forTeacher($tenant->id, $user->id, $today);

            return Inertia::render('portal/teacher-dashboard', [
                'date' => $today->toDateString(),
                'lessons' => $lessons->map(fn (array $entry) => [
                    ...$this->formatScheduleEntry($entry),
                    'lessonId' => $entry['lesson']->id,
                    'className' => $entry['lesson']->schoolClass->name,
                ])->values()->all(),
            ]);
        }

        return Inertia::render('portal/no-role', [
            'name' => $user->name,
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
