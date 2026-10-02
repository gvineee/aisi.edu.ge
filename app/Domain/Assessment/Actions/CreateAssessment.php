<?php

namespace App\Domain\Assessment\Actions;

use App\Domain\Academics\Models\TeacherAssignment;
use App\Domain\Assessment\Models\Assessment;
use App\Domain\Assessment\Models\AssessmentCategory;
use App\Domain\Assessment\Models\AssessmentPeriod;
use App\Domain\Assessment\Models\GradeScale;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class CreateAssessment
{
    public function handle(
        TeacherAssignment $teacherAssignment,
        AssessmentPeriod $period,
        ?AssessmentCategory $category,
        User $creator,
        string $title,
        Carbon $assessedOn,
        string $maxScore,
    ): Assessment {
        $teacherAssignment->loadMissing('schoolClass');

        if ($period->tenant_id !== $teacherAssignment->tenant_id
            || ($category !== null && $category->tenant_id !== $teacherAssignment->tenant_id)) {
            abort(404);
        }

        if ($period->isClosed()) {
            throw ValidationException::withMessages([
                'assessment_period_id' => 'ეს შეფასების პერიოდი დახურულია.',
            ]);
        }

        if ($teacherAssignment->schoolClass !== null && $period->academic_year_id !== $teacherAssignment->schoolClass->academic_year_id) {
            throw ValidationException::withMessages([
                'assessment_period_id' => 'პერიოდი ამ კლასის სასწავლო წელს არ ეკუთვნის.',
            ]);
        }

        if ($assessedOn->lt($period->starts_on) || $assessedOn->gt($period->ends_on)) {
            throw ValidationException::withMessages([
                'assessed_on' => 'თარიღი შეფასების პერიოდის ფარგლებში უნდა იყოს.',
            ]);
        }

        $scale = GradeScale::query()
            ->where('tenant_id', $teacherAssignment->tenant_id)
            ->where('is_active', true)
            ->orderByDesc('version')
            ->first();

        $assessment = new Assessment([
            'teacher_assignment_id' => $teacherAssignment->id,
            'assessment_period_id' => $period->id,
            'assessment_category_id' => $category?->id,
            'grade_scale_id' => $scale?->id,
            'title' => $title,
            'assessed_on' => $assessedOn->toDateString(),
            'max_score' => ScoreCalculator::fromHundredths(ScoreCalculator::toHundredths($maxScore)),
            'status' => Assessment::STATUS_DRAFT,
            'created_by' => $creator->id,
        ]);
        $assessment->tenant_id = $teacherAssignment->tenant_id;
        $assessment->save();

        return $assessment;
    }
}
