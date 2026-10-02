<?php

namespace App\Domain\Assessment\Actions;

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Governance\AuditLogger;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Makes an assessment's results visible to its students and their
 * guardians. One-way: once families have seen a grade, later corrections
 * go through the versioned/reasoned edit path, not an unpublish.
 */
class PublishAssessment
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function handle(Assessment $assessment, User $actor): void
    {
        DB::transaction(function () use ($assessment, $actor) {
            $locked = Assessment::query()
                ->where('tenant_id', $assessment->tenant_id)
                ->whereKey($assessment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->isPublished()) {
                return;
            }

            $locked->loadMissing('period');

            if ($locked->period->isClosed()) {
                throw ValidationException::withMessages([
                    'period' => 'შეფასების პერიოდი დახურულია.',
                ]);
            }

            $locked->status = Assessment::STATUS_PUBLISHED;
            $locked->published_at = Carbon::now();
            $locked->save();

            $this->auditLogger->record($locked->tenant_id, 'assessment.published', $locked, $actor->id, [
                'results_count' => $locked->results()->count(),
            ]);
        });

        $assessment->refresh();
    }
}
