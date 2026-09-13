<?php

namespace App\Domain\Learning\Actions;

use App\Domain\Governance\AuditLogger;
use App\Domain\Learning\Models\AssignmentSubmission;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class GradeSubmission
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function handle(AssignmentSubmission $submission, User $grader, int $score, ?string $feedback): void
    {
        $submission->loadMissing('assignment');
        $maxScore = $submission->assignment->max_score;

        if ($score < 0 || ($maxScore !== null && $score > $maxScore)) {
            throw ValidationException::withMessages([
                'score' => 'ქულა უნდა იყოს 0-სა და მაქსიმალურ ქულას შორის.',
            ]);
        }

        $previousScore = $submission->score;

        $submission->score = $score;
        $submission->feedback = $feedback;
        $submission->status = AssignmentSubmission::STATUS_GRADED;
        $submission->graded_by = $grader->id;
        $submission->graded_at = Carbon::now();
        $submission->save();

        $this->auditLogger->record(
            $submission->tenant_id,
            'assignment_submission.graded',
            $submission,
            $grader->id,
            ['from_score' => $previousScore, 'to_score' => $score],
        );
    }
}
