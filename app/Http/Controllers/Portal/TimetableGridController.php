<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\StaffMember;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Timetable\Models\BellPeriod;
use App\Domain\Timetable\Models\Lesson;
use App\Domain\Timetable\Models\Room;
use App\Domain\Timetable\Models\TimetableVersion;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One timetable version as a week grid (days × bell periods) for a class,
 * a teacher or a room — how an imported or generated timetable is checked
 * before it is activated. Read-only; double bookings are flagged.
 */
class TimetableGridController extends Controller
{
    /** @var list<string> */
    private const VIEW_ROLES = [
        TenantMembership::ROLE_ADMIN,
        TenantMembership::ROLE_DIRECTOR,
        TenantMembership::ROLE_ACADEMIC_MANAGER,
    ];

    public function __invoke(Request $request, CurrentTenant $currentTenant): Response
    {
        $tenantId = $currentTenant->get()->id;
        abort_unless(TenantMembership::userHasAnyActiveRole($tenantId, $request->user()->id, self::VIEW_ROLES), 403);

        $versions = TimetableVersion::query()->where('tenant_id', $tenantId)->with('academicYear')->orderByDesc('valid_from')->orderByDesc('id')->get();
        $version = $versions->firstWhere('id', $request->integer('version'))
            ?? $versions->firstWhere('status', TimetableVersion::STATUS_ACTIVE)
            ?? $versions->first();
        $by = in_array($request->query('by'), ['class', 'teacher', 'room'], true) ? (string) $request->query('by') : 'class';

        $lessons = $version === null ? collect() : Lesson::query()
            ->where('tenant_id', $tenantId)->where('timetable_version_id', $version->id)
            ->with(['subject:id,name,short_name', 'schoolClass:id,name', 'studentGroup:id,name', 'teacher:id,name', 'staffMember:id,full_name,short_name', 'room:id,name'])
            ->get();

        $options = match ($by) {
            'teacher' => StaffMember::query()->where('tenant_id', $tenantId)->whereIn('id', $lessons->pluck('staff_member_id')->filter()->unique())->orderBy('full_name')->get()
                ->map(fn (StaffMember $s) => ['id' => $s->id, 'name' => $s->full_name]),
            'room' => Room::query()->where('tenant_id', $tenantId)->whereIn('id', $lessons->pluck('room_id')->filter()->unique())->orderBy('name')->get()
                ->map(fn (Room $r) => ['id' => $r->id, 'name' => $r->name]),
            default => SchoolClass::query()->where('tenant_id', $tenantId)->whereIn('id', $lessons->pluck('school_class_id')->unique())->get()
                ->sortBy('name', SORT_NATURAL)->map(fn (SchoolClass $c) => ['id' => $c->id, 'name' => $c->name]),
        };
        $options = $options->values();
        $selected = $options->firstWhere('id', $request->integer('id')) ?? $options->first();
        $column = ['class' => 'school_class_id', 'teacher' => 'staff_member_id', 'room' => 'room_id'][$by];
        $shown = $selected === null ? collect() : $lessons->where($column, $selected['id']);

        // A teacher's joint lesson is one cell; anything else sharing a cell
        // with a whole-class lesson (or the same group) is a double booking.
        $cells = $shown->groupBy(fn (Lesson $l) => "{$l->day_of_week}|{$l->period_number}")
            ->map(function ($inCell) use ($by) {
                $events = $inCell->groupBy(fn (Lesson $l) => $l->joint_key ?? "lesson:{$l->id}");
                $groups = $inCell->pluck('student_group_id');
                $clash = match ($by) {
                    'class' => $inCell->count() > 1 && ($groups->contains(null) || $groups->duplicates()->isNotEmpty()),
                    default => $events->count() > 1,
                };

                return [
                    'conflict' => $clash,
                    'items' => $events->map(function ($event) {
                        /** @var Lesson $first */
                        $first = $event->first();

                        return [
                            'subject' => $first->subject->short_name ?? $first->subject->name ?? '',
                            'subjectFull' => $first->subject->name ?? '',
                            'classes' => $event->map(fn (Lesson $l) => $l->schoolClass->name ?? '')->unique()->sort(SORT_NATURAL)->values()->all(),
                            'group' => $first->studentGroup?->name,
                            'teacher' => $first->teacherName(),
                            'room' => $first->room?->name,
                        ];
                    })->values()->all(),
                ];
            });

        return Inertia::render('portal/timetable/grid', [
            'versions' => $versions->map(fn (TimetableVersion $v) => [
                'id' => $v->id, 'label' => $v->label, 'status' => $v->status, 'yearName' => $v->academicYear->name ?? '',
                'validFrom' => $v->valid_from->toDateString(), 'validTo' => $v->valid_to?->toDateString(),
            ])->values(),
            'versionId' => $version?->id,
            'by' => $by,
            'options' => $options,
            'selectedId' => $selected['id'] ?? null,
            'periods' => $version === null ? [] : BellPeriod::query()->where('tenant_id', $tenantId)->where('academic_year_id', $version->academic_year_id)->orderBy('number')->get()
                ->map(fn (BellPeriod $p) => ['number' => $p->number, 'startsAt' => substr($p->starts_at, 0, 5), 'endsAt' => substr($p->ends_at, 0, 5)])->values(),
            'cells' => $cells,
            'summary' => [
                'lessons' => $lessons->unique(fn (Lesson $l) => $l->joint_key ?? "lesson:{$l->id}")->count(),
                'rows' => $lessons->count(),
                'shown' => $shown->unique(fn (Lesson $l) => $l->joint_key ?? "lesson:{$l->id}")->count(),
                'conflicts' => $cells->where('conflict', true)->count(),
            ],
        ]);
    }
}
