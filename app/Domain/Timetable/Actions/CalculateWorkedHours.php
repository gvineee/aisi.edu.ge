<?php

namespace App\Domain\Timetable\Actions;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\StaffMember;
use App\Domain\Timetable\Models\Lesson;
use App\Domain\Timetable\Models\LessonException;
use App\Domain\Timetable\Models\NonTeachingPeriod;
use App\Domain\Timetable\Models\SubstitutionAssignment;
use App\Domain\Timetable\Models\TimetableVersion;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Lessons each person actually taught in a date range — the figure the
 * accountant pays by. For every school day it takes the timetable in
 * effect that day (the same rule as ResolveDailyLessons: published lessons
 * of the latest non-draft version covering the date, else the unversioned
 * ones), skips holidays and days outside the academic year, and then:
 *
 * - a joint lesson (several classes, one teacher) counts once;
 * - a cancelled lesson is nobody's hour;
 * - a substituted lesson is the substitute's hour, not the regular
 *   teacher's — whether recorded as a substitution exception or as an
 *   assigned cover.
 *
 * taught = planned − cancelled − handed over + covered. Minutes come from
 * the lessons' own start/end times. No money: rates are the school's to set.
 */
class CalculateWorkedHours
{
    /**
     * @return array{from: string, to: string, school_days: int, rows: list<array<string, mixed>>}
     */
    public function handle(int $tenantId, Carbon $from, Carbon $to): array
    {
        $years = AcademicYear::query()->where('tenant_id', $tenantId)
            ->whereDate('starts_on', '<=', $to->toDateString())->whereDate('ends_on', '>=', $from->toDateString())->get();
        $breaks = NonTeachingPeriod::query()->where('tenant_id', $tenantId)
            ->whereDate('starts_on', '<=', $to->toDateString())->whereDate('ends_on', '>=', $from->toDateString())->get();
        $versions = TimetableVersion::query()->where('tenant_id', $tenantId)
            ->where('status', '!=', TimetableVersion::STATUS_DRAFT)
            ->whereDate('valid_from', '<=', $to->toDateString())
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $from->toDateString()))
            ->orderByDesc('valid_from')->orderByDesc('id')->get();
        $lessons = Lesson::query()->where('tenant_id', $tenantId)->where('status', Lesson::STATUS_PUBLISHED)
            ->whereIn('academic_year_id', $years->pluck('id'))
            ->get(['id', 'academic_year_id', 'timetable_version_id', 'teacher_id', 'staff_member_id', 'day_of_week', 'starts_at', 'ends_at', 'joint_key']);
        $exceptions = LessonException::query()->where('tenant_id', $tenantId)
            ->whereBetween('occurs_on', [$from->toDateString(), $to->toDateString()])
            ->get(['lesson_id', 'occurs_on', 'type', 'substitute_teacher_id'])
            ->keyBy(fn (LessonException $e) => $e->lesson_id.'|'.Carbon::parse($e->occurs_on)->toDateString());
        $covers = SubstitutionAssignment::query()->where('tenant_id', $tenantId)
            ->where('status', SubstitutionAssignment::STATUS_ASSIGNED)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get(['lesson_id', 'date', 'substitute_teacher_id'])
            ->keyBy(fn (SubstitutionAssignment $c) => $c->lesson_id.'|'.$c->date->toDateString());

        // Everyone is reported as a staff member when they have one; a
        // substitute with only an account is reported by the account.
        $staffByUser = StaffMember::query()->where('tenant_id', $tenantId)->whereNotNull('user_id')->pluck('id', 'user_id');
        $person = fn (?int $staffId, ?int $userId): ?string => $staffId !== null ? "staff:{$staffId}" : ($userId === null ? null : (isset($staffByUser[$userId]) ? 'staff:'.$staffByUser[$userId] : "user:{$userId}"));

        /** Per field, per person: lesson counts; and taught minutes per person. */
        $counts = ['planned' => [], 'cancelled' => [], 'handed_over' => [], 'covered' => []];
        $minutesTaught = [];
        $add = function (?string $who, string $field, int $minutes) use (&$counts, &$minutesTaught) {
            if ($who === null) {
                return;
            }

            $counts[$field][$who] = ($counts[$field][$who] ?? 0) + 1;
            $minutesTaught[$who] = ($minutesTaught[$who] ?? 0) + (in_array($field, ['planned', 'covered'], true) ? $minutes : -$minutes);
        };
        $schoolDays = 0;

        for ($day = $from->copy()->startOfDay(); $day->lte($to); $day->addDay()) {
            $date = $day->toDateString();
            $year = $years->first(fn (AcademicYear $y) => $y->starts_on->toDateString() <= $date && $y->ends_on->toDateString() >= $date);

            if ($year === null || $breaks->contains(fn (NonTeachingPeriod $b) => $b->academic_year_id === $year->id && $b->starts_on->toDateString() <= $date && $b->ends_on->toDateString() >= $date)) {
                continue;
            }

            $version = $versions->first(fn (TimetableVersion $v) => $v->academic_year_id === $year->id
                && $v->valid_from->toDateString() <= $date && ($v->valid_to === null || $v->valid_to->toDateString() >= $date));
            $today = $lessons->filter(fn (Lesson $l) => $l->academic_year_id === $year->id
                && $l->timetable_version_id === $version?->id && $l->day_of_week === $day->dayOfWeekIso);

            if ($today->isEmpty()) {
                continue;
            }

            $schoolDays++;

            foreach ($today->groupBy(fn (Lesson $l) => $l->joint_key ?? "lesson:{$l->id}") as $event) {
                /** @var Lesson $lesson */
                $lesson = $event->first();
                $minutes = $this->minutes($lesson->starts_at, $lesson->ends_at);
                $regular = $person($lesson->staff_member_id, $lesson->teacher_id);
                $states = $event->map(fn (Lesson $l) => $exceptions->get("{$l->id}|{$date}"));
                $add($regular, 'planned', $minutes);

                // A joint lesson is cancelled only when every class's part is.
                if ($states->every(fn (?LessonException $e) => $e?->type === LessonException::TYPE_CANCELLED)) {
                    $add($regular, 'cancelled', $minutes);

                    continue;
                }

                $substituteUser = $states->first(fn (?LessonException $e) => $e?->type === LessonException::TYPE_SUBSTITUTION && $e->substitute_teacher_id !== null)->substitute_teacher_id
                    ?? $event->map(fn (Lesson $l) => $covers->get("{$l->id}|{$date}")?->substitute_teacher_id)->filter()->first();
                $substitute = $person(null, $substituteUser);

                if ($substitute !== null && $substitute !== $regular) {
                    $add($regular, 'handed_over', $minutes);
                    $add($substitute, 'covered', $minutes);
                }
            }
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'school_days' => $schoolDays,
            'rows' => $this->rows($tenantId, $counts, $minutesTaught),
        ];
    }

    /**
     * @param  array<string, array<string, int>>  $counts
     * @param  array<string, int>  $minutesTaught
     * @return list<array<string, mixed>>
     */
    private function rows(int $tenantId, array $counts, array $minutesTaught): array
    {
        $people = array_keys($minutesTaught);
        $staff = StaffMember::query()->where('tenant_id', $tenantId)->get()->keyBy('id');
        $users = User::query()->whereIn('id', collect($people)->filter(fn (string $k) => str_starts_with($k, 'user:'))->map(fn (string $k) => (int) substr($k, 5)))->get()->keyBy('id');

        $rows = collect($people)
            ->map(function (string $who) use ($staff, $users, $counts, $minutesTaught) {
                [$kind, $id] = explode(':', $who);
                $member = $kind === 'staff' ? $staff->get((int) $id) : null;
                $t = fn (string $field): int => $counts[$field][$who] ?? 0;

                return [
                    'key' => $who,
                    'staffMemberId' => $member?->id,
                    'name' => $member !== null ? $member->full_name : (string) ($users->get((int) $id)->name ?? ''),
                    'isPlaceholder' => $member !== null && $member->is_placeholder,
                    'hasAccount' => $kind === 'user' || $member?->user_id !== null,
                    'planned' => $t('planned'),
                    'cancelled' => $t('cancelled'),
                    'handedOver' => $t('handed_over'),
                    'covered' => $t('covered'),
                    'taught' => $t('planned') - $t('cancelled') - $t('handed_over') + $t('covered'),
                    'minutes' => $minutesTaught[$who],
                ];
            })
            ->sortBy('name', SORT_NATURAL)
            ->all();

        return array_values($rows);
    }

    private function minutes(string $start, string $end): int
    {
        [$sh, $sm] = array_map('intval', explode(':', $start));
        [$eh, $em] = array_map('intval', explode(':', $end));

        return max(0, ($eh * 60 + $em) - ($sh * 60 + $sm));
    }
}
