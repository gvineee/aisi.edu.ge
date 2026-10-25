<?php

namespace App\Domain\Timetable\Actions;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Governance\AuditLogger;
use App\Domain\Timetable\Models\BellPeriod;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Replaces a year's bell schedule (period number → start/end). Periods
 * must run in order without overlapping. Existing lessons keep their own
 * times; the schedule only pre-fills new ones.
 */
class SaveBellSchedule
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  list<array{starts_at: string, ends_at: string}>  $periods  in period order, H:i
     */
    public function handle(AcademicYear $year, User $actor, array $periods): void
    {
        $previousEnd = null;

        foreach ($periods as $index => $period) {
            if ($period['ends_at'] <= $period['starts_at'] || ($previousEnd !== null && $period['starts_at'] < $previousEnd)) {
                throw ValidationException::withMessages(["periods.{$index}.starts_at" => 'პერიოდები თანმიმდევრულად და გადაფარვის გარეშე უნდა იყოს.']);
            }

            $previousEnd = $period['ends_at'];
        }

        DB::transaction(function () use ($year, $actor, $periods) {
            BellPeriod::query()->where('tenant_id', $year->tenant_id)->where('academic_year_id', $year->id)->delete();

            foreach ($periods as $index => $period) {
                $bell = new BellPeriod([
                    'academic_year_id' => $year->id,
                    'number' => $index + 1,
                    'starts_at' => $period['starts_at'].':00',
                    'ends_at' => $period['ends_at'].':00',
                ]);
                $bell->tenant_id = $year->tenant_id;
                $bell->save();
            }

            $this->auditLogger->record($year->tenant_id, 'bell_schedule.saved', $year, $actor->id, ['periods' => count($periods)]);
        });
    }
}
