<?php

namespace App\Domain\Assessment\Actions;

use App\Domain\Academics\Models\Student;
use App\Domain\Assessment\Models\Assessment;
use App\Domain\Assessment\Models\AssessmentPeriod;
use App\Domain\Assessment\Models\AssessmentResult;
use App\Domain\Assessment\Models\AssessmentResultRevision;
use App\Domain\Governance\AuditLogger;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves a teacher's scores for one assessment. Every saved value becomes a
 * new version with an append-only revision row; changing an existing score
 * requires a reason and is audit-logged. Edits are rejected once the
 * period is closed, and a stale form (someone saved in between) is
 * rejected via the version check instead of silently overwriting.
 */
class RecordAssessmentResults
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly ScoreCalculator $calculator,
    ) {}

    /**
     * @param  array<int, array{student_id: int, raw_score: string|int|float, comment?: string|null, expected_version?: int|null}>  $entries
     * @return int number of results created or changed
     */
    public function handle(Assessment $assessment, User $actor, array $entries, ?string $reason): int
    {
        $assessment->loadMissing('teacherAssignment', 'gradeScale.bands');
        $maxHundredths = ScoreCalculator::toHundredths($assessment->max_score);
        $reason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;

        $rosterIds = Student::query()
            ->where('tenant_id', $assessment->tenant_id)
            ->where('school_class_id', $assessment->teacherAssignment->school_class_id)
            ->where('is_active', true)
            ->pluck('id')
            ->all();

        foreach ($entries as $index => $entry) {
            if (! in_array((int) $entry['student_id'], $rosterIds, true)) {
                throw ValidationException::withMessages([
                    "results.{$index}.student_id" => 'მოსწავლე ამ კლასის აქტიურ სიაში არ არის.',
                ]);
            }

            $score = ScoreCalculator::toHundredths($entry['raw_score']);

            if ($score < 0 || $score > $maxHundredths) {
                throw ValidationException::withMessages([
                    "results.{$index}.raw_score" => 'ქულა უნდა იყოს 0-სა და მაქსიმალურ ქულას შორის.',
                ]);
            }
        }

        try {
            return DB::transaction(function () use ($assessment, $actor, $entries, $reason) {
                // Re-read the period under lock so a concurrent close wins
                // cleanly instead of racing a late edit through.
                $period = AssessmentPeriod::query()
                    ->where('tenant_id', $assessment->tenant_id)
                    ->whereKey($assessment->assessment_period_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($period->isClosed()) {
                    throw ValidationException::withMessages([
                        'period' => 'შეფასების პერიოდი დახურულია — ცვლილება შეუძლებელია.',
                    ]);
                }

                $changed = 0;

                foreach ($entries as $index => $entry) {
                    $changed += $this->saveOne($assessment, $actor, $entry, $reason, $index) ? 1 : 0;
                }

                return $changed;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'results' => 'სხვა მომხმარებელმა ამავდროულად შეინახა ქულები. განაახლეთ გვერდი და სცადეთ თავიდან.',
            ]);
        }
    }

    /**
     * @param  array{student_id: int, raw_score: string|int|float, comment?: string|null, expected_version?: int|null}  $entry
     */
    private function saveOne(Assessment $assessment, User $actor, array $entry, ?string $reason, int $index): bool
    {
        $rawScore = ScoreCalculator::fromHundredths(ScoreCalculator::toHundredths($entry['raw_score']));
        $comment = isset($entry['comment']) && trim((string) $entry['comment']) !== '' ? trim((string) $entry['comment']) : null;
        $percent = $this->calculator->normalizedPercent($rawScore, $assessment->max_score);
        $finalGrade = $this->calculator->finalGrade($assessment->gradeScale, $percent);

        $result = AssessmentResult::query()
            ->where('tenant_id', $assessment->tenant_id)
            ->where('assessment_id', $assessment->id)
            ->where('student_id', (int) $entry['student_id'])
            ->lockForUpdate()
            ->first();

        if ($result === null) {
            $result = new AssessmentResult([
                'assessment_id' => $assessment->id,
                'student_id' => (int) $entry['student_id'],
                'raw_score' => $rawScore,
                'normalized_percent' => $percent,
                'final_grade' => $finalGrade,
                'comment' => $comment,
                'version' => 1,
                'recorded_by' => $actor->id,
            ]);
            $result->tenant_id = $assessment->tenant_id;
            $result->save();

            $this->recordRevision($result, $actor, null);
            $this->auditLogger->record($assessment->tenant_id, 'assessment_result.recorded', $result, $actor->id, [
                'assessment_id' => $assessment->id,
                'student_id' => $result->student_id,
                'raw_score' => $rawScore,
            ]);

            return true;
        }

        $expectedVersion = $entry['expected_version'] ?? null;

        if ($expectedVersion !== null && (int) $expectedVersion !== $result->version) {
            throw ValidationException::withMessages([
                "results.{$index}.raw_score" => 'ეს ქულა ამასობაში სხვამ შეცვალა. განაახლეთ გვერდი.',
            ]);
        }

        if ($result->raw_score === $rawScore && $result->comment === $comment) {
            return false;
        }

        if ($reason === null) {
            throw ValidationException::withMessages([
                'reason' => 'უკვე შენახული ქულის შეცვლისთვის მიზეზის მითითება სავალდებულოა.',
            ]);
        }

        $previous = ['raw_score' => $result->raw_score, 'version' => $result->version];

        $result->raw_score = $rawScore;
        $result->normalized_percent = $percent;
        $result->final_grade = $finalGrade;
        $result->comment = $comment;
        $result->version = $result->version + 1;
        $result->recorded_by = $actor->id;
        $result->save();

        $this->recordRevision($result, $actor, $reason);
        $this->auditLogger->record($assessment->tenant_id, 'assessment_result.changed', $result, $actor->id, [
            'assessment_id' => $assessment->id,
            'student_id' => $result->student_id,
            'from_score' => $previous['raw_score'],
            'to_score' => $rawScore,
            'from_version' => $previous['version'],
            'to_version' => $result->version,
            'reason' => $reason,
        ]);

        return true;
    }

    private function recordRevision(AssessmentResult $result, User $actor, ?string $reason): void
    {
        $revision = new AssessmentResultRevision([
            'assessment_result_id' => $result->id,
            'version' => $result->version,
            'raw_score' => $result->raw_score,
            'normalized_percent' => $result->normalized_percent,
            'final_grade' => $result->final_grade,
            'reason' => $reason,
            'changed_by' => $actor->id,
        ]);
        $revision->tenant_id = $result->tenant_id;
        $revision->save();
    }
}
