<?php

namespace App\Domain\Timetable\Actions;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\TeacherAssignment;
use App\Domain\Governance\AuditLogger;
use App\Domain\Timetable\Models\BellPeriod;
use App\Domain\Timetable\Models\Lesson;
use App\Domain\Timetable\Models\TimetableVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Builds a new draft timetable version from the year's workloads (who
 * teaches what to which class or group, how many lessons a week).
 *
 * Hard rules — never broken: a teacher is in one place at a time (a joint
 * lesson of several classes is one place); a whole-class lesson has the
 * class to itself; two different groups of a class may meet at once, the
 * same group may not; at most two lessons of one subject per class a day.
 *
 * Preferences — weighed, deterministic (same input, same timetable):
 * spread a subject over the week, start the day early and leave no gaps in
 * a class's day, balance each teacher's days. The hardest workloads
 * (joint lessons, busy teachers, many hours) go first; a lesson that finds
 * no free slot gets one repair attempt (moving one already placed lesson),
 * otherwise it is reported as unplaced for the timetabler to place by hand.
 *
 * Nothing existing changes: the result is a draft, checked in the grid
 * view and activated separately.
 */
class GenerateTimetable
{
    private const DAYS = 5;

    /** @var array<string, true> "staff|day|period" */
    private array $staffBusy = [];

    /** @var array<string, list<int|null>> "class|day|period" → groups in that slot (null = whole class) */
    private array $classSlots = [];

    /** @var array<string, int> "class|subject|day" → lessons */
    private array $subjectDay = [];

    /** @var array<string, int> "staff|day" → lessons */
    private array $staffDay = [];

    /** @var array<int, array{unit: int, day: int, period: int}> placement id → placement */
    private array $placed = [];

    private int $nextPlacement = 0;

    /** @var list<array{staff_member_id: int, user_id: int|null, subject_id: int, hours: int, targets: list<array{0: int, 1: int|null}>, subject: string, classes: string, teacher: string, staff_load: int}> */
    private array $units = [];

    private int $periods = 0;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @return array{version: TimetableVersion, placed: int, unplaced: list<array{subject: string, classes: string, teacher: string, missing: int}>}
     */
    public function handle(AcademicYear $year, User $actor, string $label, string $validFrom, ?string $validTo = null, ?int $periodsPerDay = null): array
    {
        $bells = BellPeriod::query()->where('tenant_id', $year->tenant_id)->where('academic_year_id', $year->id)->orderBy('number')->get()->keyBy('number');

        if ($bells->isEmpty()) {
            throw new InvalidArgumentException('ჯერ ზარების განრიგი შეიყვანეთ.');
        }

        $this->periods = min($periodsPerDay ?? $bells->count(), $bells->count());
        $this->loadUnits($year);

        if ($this->units === []) {
            throw new InvalidArgumentException('ამ წელს დატვირთვა (მასწავლებელი × კლასი × საათი) არ არის.');
        }

        $order = array_keys($this->units);
        usort($order, fn (int $a, int $b) => $this->difficulty($this->units[$b]) <=> $this->difficulty($this->units[$a]) ?: $a <=> $b);
        $unplaced = [];

        foreach ($order as $index) {
            for ($n = 0; $n < $this->units[$index]['hours']; $n++) {
                $slot = $this->bestSlot($index) ?? $this->repair($index);

                if ($slot === null) {
                    $unplaced[$index] = ($unplaced[$index] ?? 0) + 1;

                    continue;
                }

                $this->place($index, $slot[0], $slot[1]);
            }
        }

        $version = DB::transaction(function () use ($year, $actor, $label, $validFrom, $validTo, $bells) {
            $version = new TimetableVersion(['academic_year_id' => $year->id, 'label' => $label, 'valid_from' => $validFrom, 'valid_to' => $validTo, 'status' => TimetableVersion::STATUS_DRAFT]);
            $version->tenant_id = $year->tenant_id;
            $version->save();
            $homeRooms = SchoolClass::query()->where('tenant_id', $year->tenant_id)->where('academic_year_id', $year->id)->pluck('home_room_id', 'id');

            foreach ($this->placed as $number => $placement) {
                $unit = $this->units[$placement['unit']];
                $bell = $bells->get($placement['period']);

                foreach ($unit['targets'] as [$classId, $groupId]) {
                    $lesson = new Lesson([
                        'academic_year_id' => $year->id,
                        'timetable_version_id' => $version->id,
                        'school_class_id' => $classId,
                        'student_group_id' => $groupId,
                        'subject_id' => $unit['subject_id'],
                        'staff_member_id' => $unit['staff_member_id'],
                        'teacher_id' => $unit['user_id'],
                        'room_id' => $groupId === null && count($unit['targets']) === 1 ? ($homeRooms[$classId] ?? null) : null,
                        'day_of_week' => $placement['day'],
                        'period_number' => $placement['period'],
                        'starts_at' => $bell->starts_at,
                        'ends_at' => $bell->ends_at,
                        'joint_key' => count($unit['targets']) > 1 ? "generated:{$version->id}:{$number}" : null,
                        'status' => Lesson::STATUS_PUBLISHED,
                        'created_by' => $actor->id,
                    ]);
                    $lesson->tenant_id = $year->tenant_id;
                    $lesson->save();
                }
            }

            return $version;
        });

        $report = [];

        foreach ($unplaced as $index => $missing) {
            $unit = $this->units[$index];
            $report[] = ['subject' => $unit['subject'], 'classes' => $unit['classes'], 'teacher' => $unit['teacher'], 'missing' => $missing];
        }

        $this->auditLogger->record($year->tenant_id, 'timetable.generated', $version, $actor->id, [
            'placed' => count($this->placed), 'unplaced' => array_sum($unplaced), 'periods_per_day' => $this->periods,
        ]);

        return ['version' => $version, 'placed' => count($this->placed), 'unplaced' => $report];
    }

    /**
     * One unit per workload, or per joint lesson (its classes together).
     */
    private function loadUnits(AcademicYear $year): void
    {
        $workloads = TeacherAssignment::query()
            ->where('tenant_id', $year->tenant_id)->whereNotNull('staff_member_id')->whereNotNull('subject_id')->where('weekly_hours', '>', 0)
            ->whereHas('schoolClass', fn ($q) => $q->where('academic_year_id', $year->id))
            ->with(['schoolClass:id,name', 'subjectModel:id,name', 'staffMember:id,full_name,user_id'])
            ->orderBy('id')->get();

        foreach ($workloads->groupBy(fn (TeacherAssignment $a) => $a->joint_key ?? "assignment:{$a->id}") as $rows) {
            /** @var TeacherAssignment $first */
            $first = $rows->first();
            if ($first->staff_member_id === null || $first->subject_id === null) {
                continue;
            }

            $this->units[] = [
                'staff_member_id' => $first->staff_member_id,
                'user_id' => $first->staffMember->user_id ?? null,
                'subject_id' => $first->subject_id,
                'hours' => (int) $rows->max('weekly_hours'),
                'targets' => array_values($rows->map(fn (TeacherAssignment $a): array => [$a->school_class_id, $a->student_group_id])->all()),
                'subject' => $first->subjectModel->name ?? (string) $first->subject,
                'classes' => $rows->map(fn (TeacherAssignment $a): string => $a->schoolClass->name ?? '')->implode(', '),
                'teacher' => $first->staffMember->full_name ?? '',
                'staff_load' => 0,
            ];
        }

        $load = [];

        foreach ($this->units as $unit) {
            $load[$unit['staff_member_id']] = ($load[$unit['staff_member_id']] ?? 0) + $unit['hours'];
        }

        foreach ($this->units as $i => $unit) {
            $this->units[$i]['staff_load'] = $load[$unit['staff_member_id']];
        }
    }

    /**
     * @param  array{targets: list<array{0: int, 1: int|null}>, staff_load: int, hours: int}  $unit
     */
    private function difficulty(array $unit): int
    {
        return count($unit['targets']) * 1000 + $unit['staff_load'] * 10 + $unit['hours'];
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private function bestSlot(int $index): ?array
    {
        $best = null;
        $bestCost = PHP_INT_MAX;

        for ($day = 1; $day <= self::DAYS; $day++) {
            for ($period = 1; $period <= $this->periods; $period++) {
                if (! $this->fits($index, $day, $period)) {
                    continue;
                }

                $cost = $this->cost($index, $day, $period);

                if ($cost < $bestCost) {
                    [$best, $bestCost] = [[$day, $period], $cost];
                }
            }
        }

        return $best;
    }

    private function fits(int $index, int $day, int $period, ?int $ignorePlacement = null): bool
    {
        $unit = $this->units[$index];
        $ignored = $ignorePlacement === null ? null : $this->placed[$ignorePlacement];
        $ignoredUnit = $ignored === null ? null : $this->units[$ignored['unit']];
        $sameSlotAsIgnored = $ignored !== null && $ignored['day'] === $day && $ignored['period'] === $period;

        if (isset($this->staffBusy["{$unit['staff_member_id']}|{$day}|{$period}"])
            && ! ($sameSlotAsIgnored && $ignoredUnit['staff_member_id'] === $unit['staff_member_id'])) {
            return false;
        }

        foreach ($unit['targets'] as [$classId, $groupId]) {
            $groups = $this->classSlots["{$classId}|{$day}|{$period}"] ?? [];

            if ($sameSlotAsIgnored) {
                foreach ($ignoredUnit['targets'] as [$ignoredClass, $ignoredGroup]) {
                    if ($ignoredClass === $classId && ($pos = array_search($ignoredGroup, $groups, true)) !== false) {
                        unset($groups[$pos]);
                    }
                }
            }

            if ($groups !== [] && ($groupId === null || in_array(null, $groups, true) || in_array($groupId, $groups, true))) {
                return false;
            }

            $sameDay = $this->subjectDay["{$classId}|{$unit['subject_id']}|{$day}"] ?? 0;

            if ($ignored !== null && $ignored['day'] === $day && $ignoredUnit['subject_id'] === $unit['subject_id']
                && in_array($classId, array_column($ignoredUnit['targets'], 0), true)) {
                $sameDay--;
            }

            if ($sameDay >= 2) {
                return false;
            }
        }

        return true;
    }

    private function cost(int $index, int $day, int $period): int
    {
        $unit = $this->units[$index];
        $cost = $period * 2 + ($this->staffDay["{$unit['staff_member_id']}|{$day}"] ?? 0);

        foreach ($unit['targets'] as [$classId]) {
            $cost += 25 * ($this->subjectDay["{$classId}|{$unit['subject_id']}|{$day}"] ?? 0);

            // Keep the class's day compact: no hole before this lesson.
            if ($period > 1 && ! isset($this->classSlots["{$classId}|{$day}|".($period - 1)])) {
                $cost += 6;
            }
        }

        return $cost;
    }

    private function place(int $index, int $day, int $period): int
    {
        $unit = $this->units[$index];
        $this->staffBusy["{$unit['staff_member_id']}|{$day}|{$period}"] = true;
        $this->staffDay["{$unit['staff_member_id']}|{$day}"] = ($this->staffDay["{$unit['staff_member_id']}|{$day}"] ?? 0) + 1;

        foreach ($unit['targets'] as [$classId, $groupId]) {
            $this->classSlots["{$classId}|{$day}|{$period}"][] = $groupId;
            $this->subjectDay["{$classId}|{$unit['subject_id']}|{$day}"] = ($this->subjectDay["{$classId}|{$unit['subject_id']}|{$day}"] ?? 0) + 1;
        }

        $id = $this->nextPlacement++;
        $this->placed[$id] = ['unit' => $index, 'day' => $day, 'period' => $period];

        return $id;
    }

    private function unplace(int $placement): void
    {
        ['unit' => $index, 'day' => $day, 'period' => $period] = $this->placed[$placement];
        $unit = $this->units[$index];
        unset($this->staffBusy["{$unit['staff_member_id']}|{$day}|{$period}"]);
        $this->staffDay["{$unit['staff_member_id']}|{$day}"]--;

        foreach ($unit['targets'] as [$classId, $groupId]) {
            $key = "{$classId}|{$day}|{$period}";
            $remaining = $this->classSlots[$key];
            $pos = array_search($groupId, $remaining, true);

            if ($pos !== false) {
                array_splice($remaining, $pos, 1);
            }

            if ($remaining === []) {
                unset($this->classSlots[$key]);
            } else {
                $this->classSlots[$key] = $remaining;
            }

            $this->subjectDay["{$classId}|{$unit['subject_id']}|{$day}"]--;
        }

        unset($this->placed[$placement]);
    }

    /**
     * No free slot: find a slot blocked by exactly one placed lesson that
     * itself has another free slot, move it there and take its place.
     *
     * @return array{0: int, 1: int}|null
     */
    private function repair(int $index): ?array
    {
        $snapshot = $this->placed;

        foreach ($snapshot as $p => $placement) {
            // Earlier repair attempts re-place lessons under new ids.
            if (! array_key_exists($p, $this->placed) || ! $this->fits($index, $placement['day'], $placement['period'], $p)) {
                continue;
            }

            $moved = $placement['unit'];
            $from = [$placement['day'], $placement['period']];
            $this->unplace($p);
            $target = $this->bestSlot($moved);

            if ($target !== null && $target !== $from) {
                $movedTo = $this->place($moved, $target[0], $target[1]);

                if ($this->fits($index, $from[0], $from[1])) {
                    return $from;
                }

                $this->unplace($movedTo);
            }

            $this->place($moved, $from[0], $from[1]);
        }

        return null;
    }
}
