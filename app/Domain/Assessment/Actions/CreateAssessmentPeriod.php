<?php

namespace App\Domain\Assessment\Actions;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Assessment\Models\AssessmentPeriod;
use App\Domain\Governance\AuditLogger;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class CreateAssessmentPeriod
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function handle(AcademicYear $academicYear, User $actor, string $name, Carbon $startsOn, Carbon $endsOn): AssessmentPeriod
    {
        if ($startsOn->lt($academicYear->starts_on) || $endsOn->gt($academicYear->ends_on)) {
            throw ValidationException::withMessages([
                'starts_on' => 'პერიოდი სასწავლო წლის ფარგლებში უნდა იყოს.',
            ]);
        }

        $period = new AssessmentPeriod([
            'academic_year_id' => $academicYear->id,
            'name' => $name,
            'starts_on' => $startsOn->toDateString(),
            'ends_on' => $endsOn->toDateString(),
            'status' => AssessmentPeriod::STATUS_OPEN,
        ]);
        $period->tenant_id = $academicYear->tenant_id;
        $period->save();

        $this->auditLogger->record($period->tenant_id, 'assessment_period.created', $period, $actor->id);

        return $period;
    }
}
