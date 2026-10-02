<?php

namespace App\Domain\Assessment\Actions;

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Assessment\Models\AssessmentResult;

/**
 * Informational summary of one student's *published* results for one
 * class/subject in one period: how many scored assessments and their
 * unweighted mean percent. Never a final grade — weighting is the school's
 * unapproved policy, so the teacher's final mark stays a human decision.
 */
class SummarizeSubjectResults
{
    /**
     * @return array{count: int, average_percent: string|null}
     */
    public function handle(int $tenantId, int $studentId, int $teacherAssignmentId, int $periodId): array
    {
        $percents = AssessmentResult::query()
            ->where('tenant_id', $tenantId)
            ->where('student_id', $studentId)
            ->whereIn('assessment_id', Assessment::query()
                ->where('tenant_id', $tenantId)
                ->where('teacher_assignment_id', $teacherAssignmentId)
                ->where('assessment_period_id', $periodId)
                ->where('status', Assessment::STATUS_PUBLISHED)
                ->select('id'))
            ->pluck('normalized_percent');

        if ($percents->isEmpty()) {
            return ['count' => 0, 'average_percent' => null];
        }

        $sumHundredths = $percents->sum(fn ($percent) => ScoreCalculator::toHundredths((string) $percent));

        return [
            'count' => $percents->count(),
            'average_percent' => ScoreCalculator::fromHundredths((int) round($sumHundredths / $percents->count())),
        ];
    }
}
