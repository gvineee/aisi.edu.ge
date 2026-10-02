<?php

namespace App\Domain\Assessment\Actions;

use App\Domain\Assessment\Models\AssessmentPeriod;
use App\Domain\Governance\AuditLogger;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Closing locks every result in the period; reopening is the exceptional
 * path and always needs a reason, both audit-logged.
 */
class ChangeAssessmentPeriodStatus
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function close(AssessmentPeriod $period, User $actor): void
    {
        DB::transaction(function () use ($period, $actor) {
            $locked = $this->lock($period);

            if ($locked->isClosed()) {
                return;
            }

            $locked->status = AssessmentPeriod::STATUS_CLOSED;
            $locked->closed_at = Carbon::now();
            $locked->closed_by = $actor->id;
            $locked->save();

            $this->auditLogger->record($locked->tenant_id, 'assessment_period.closed', $locked, $actor->id);
        });

        $period->refresh();
    }

    public function reopen(AssessmentPeriod $period, User $actor, string $reason): void
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'ხელახლა გახსნის მიზეზი სავალდებულოა.']);
        }

        DB::transaction(function () use ($period, $actor, $reason) {
            $locked = $this->lock($period);

            if (! $locked->isClosed()) {
                return;
            }

            $locked->status = AssessmentPeriod::STATUS_OPEN;
            $locked->closed_at = null;
            $locked->closed_by = null;
            $locked->save();

            $this->auditLogger->record($locked->tenant_id, 'assessment_period.reopened', $locked, $actor->id, [
                'reason' => trim($reason),
            ]);
        });

        $period->refresh();
    }

    private function lock(AssessmentPeriod $period): AssessmentPeriod
    {
        return AssessmentPeriod::query()
            ->where('tenant_id', $period->tenant_id)
            ->whereKey($period->id)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
