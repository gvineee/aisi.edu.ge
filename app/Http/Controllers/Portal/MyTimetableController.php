<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Academics\Models\Student;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\PortalContext;
use App\Domain\Timetable\Actions\ResolveDailyLessons;
use App\Domain\Timetable\Models\Lesson;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The personal timetable („ჩემი განრიგი“ / „შვილის განრიგი“): a day or a
 * week of effective lessons for the signed-in pupil, for a guardian's
 * authorised child, or for a teacher (own lessons and today's covers).
 *
 * Who is shown is decided here from the active role and server-side links
 * only — never from a client-supplied student, class or group id; a
 * guardian's `child` parameter merely picks among their own authorised
 * children. Every day comes from ResolveDailyLessons, the same source as the
 * dashboards, so day and week never disagree. The admin timetable editor
 * (/portal/timetable) is a separate screen.
 */
class MyTimetableController extends Controller
{
    private const ROLES = [TenantMembership::ROLE_STUDENT, TenantMembership::ROLE_GUARDIAN, TenantMembership::ROLE_TEACHER];

    public function __invoke(Request $request, CurrentTenant $currentTenant, PortalContext $portalContext, ResolveDailyLessons $resolver): Response
    {
        $tenant = $currentTenant->get();
        $user = $request->user();
        $role = $portalContext->resolveActiveRole($request, $tenant->id, $user->id);
        abort_unless(in_array($role, self::ROLES, true), 403);

        $today = Carbon::now($tenant->timezone)->startOfDay();
        $date = $this->requestedDate($request, $tenant, $today);
        $view = $request->query('view') === 'day' ? 'day' : 'week';

        $children = [];
        $selectedChildId = null;
        $state = 'ok';
        $resolveDay = null;

        if ($role === TenantMembership::ROLE_GUARDIAN) {
            $links = GuardianLink::query()
                ->where('tenant_id', $tenant->id)
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->where('can_view_academic', true)
                ->whereHas('student', fn ($query) => $query->where('tenant_id', $tenant->id)->where('is_active', true))
                ->with('student.schoolClass')
                ->get();

            $children = $links->map(fn (GuardianLink $link) => [
                'id' => $link->student->id,
                'name' => $link->student->fullName(),
                'className' => $link->student->schoolClass?->name,
            ])->values()->all();

            $requested = (int) $request->query('child');
            $child = $links->first(fn (GuardianLink $link) => $link->student->id === $requested)->student
                ?? $links->first()?->student;

            if ($child === null) {
                $state = 'no_children';
            } else {
                $selectedChildId = $child->id;
                $resolveDay = fn (Carbon $day) => $resolver->forStudent($tenant->id, $child, $day);
            }
        } elseif ($role === TenantMembership::ROLE_STUDENT) {
            $student = Student::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('is_active', true)->first();

            if ($student === null) {
                $state = 'not_linked';
            } else {
                $resolveDay = fn (Carbon $day) => $resolver->forStudent($tenant->id, $student, $day);
            }
        } else {
            $resolveDay = fn (Carbon $day) => $resolver->forTeacher($tenant->id, $user->id, $day);
        }

        $year = AcademicYear::query()
            ->where('tenant_id', $tenant->id)
            ->whereDate('starts_on', '<=', $date->toDateString())
            ->whereDate('ends_on', '>=', $date->toDateString())
            ->first();

        if ($state === 'ok' && $year === null) {
            $state = 'outside_year';
        }

        $weekStart = $date->copy()->startOfWeek(Carbon::MONDAY);
        $dates = $view === 'day'
            ? [$date->copy()]
            : array_map(fn (int $offset) => $weekStart->copy()->addDays($offset), range(0, 6));

        $days = [];

        foreach ($dates as $day) {
            $entries = $state === 'ok' && $resolveDay !== null ? $resolveDay($day) : collect();

            // Weekend days appear in the week only when something is on.
            if ($view === 'week' && $day->isoWeekday() > 5 && $entries->isEmpty()) {
                continue;
            }

            $days[] = [
                'date' => $day->toDateString(),
                'isToday' => $day->isSameDay($today),
                'lessons' => $entries->map(fn (array $entry) => $this->formatEntry($entry, $role === TenantMembership::ROLE_TEACHER, $day->isSameDay($today)))->values()->all(),
            ];
        }

        $response = Inertia::render('portal/my-timetable', [
            'role' => $role,
            'view' => $view,
            'date' => $date->toDateString(),
            'today' => $today->toDateString(),
            'weekStart' => $weekStart->toDateString(),
            'previousDate' => ($view === 'day' ? $date->copy()->subDay() : $weekStart->copy()->subWeek())->toDateString(),
            'nextDate' => ($view === 'day' ? $date->copy()->addDay() : $weekStart->copy()->addWeek())->toDateString(),
            'state' => $state,
            'children' => $children,
            'selectedChildId' => $selectedChildId,
            'days' => $days,
        ])->toResponse($request);

        // A personal timetable is never cached by browsers or proxies.
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /**
     * A valid Y-m-d within two years of today, otherwise today.
     */
    private function requestedDate(Request $request, Tenant $tenant, Carbon $today): Carbon
    {
        $raw = $request->query('date');

        if (! is_string($raw) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1) {
            return $today->copy();
        }

        $date = Carbon::createFromFormat('Y-m-d', $raw, $tenant->timezone);

        if ($date === null || $date->format('Y-m-d') !== $raw || abs($date->diffInDays($today)) > 730) {
            return $today->copy();
        }

        return $date->startOfDay();
    }

    /**
     * Only what a timetable needs: no notes, rosters or other pupil data.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function formatEntry(array $entry, bool $forTeacher, bool $isToday): array
    {
        /** @var Lesson $lesson */
        $lesson = $entry['lesson'];

        return [
            'id' => $lesson->id,
            'subject' => $lesson->subject->name,
            'startsAt' => substr($lesson->starts_at, 0, 5),
            'endsAt' => substr($lesson->ends_at, 0, 5),
            'period' => $lesson->period_number,
            'teacherName' => $entry['teacherName'],
            'regularTeacherName' => $entry['regularTeacherName'],
            'roomName' => $entry['roomName'],
            'className' => $lesson->schoolClass->name,
            'groupName' => $lesson->studentGroup?->name,
            'status' => $entry['status'],
            // The lesson page admits a substitute only on the covered day.
            'openable' => $forTeacher && match ($entry['status']) {
                ResolveDailyLessons::STATUS_HANDED_OVER => false,
                ResolveDailyLessons::STATUS_COVERING => $isToday,
                default => true,
            },
        ];
    }
}
