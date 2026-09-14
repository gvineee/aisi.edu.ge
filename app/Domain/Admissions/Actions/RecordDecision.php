<?php

namespace App\Domain\Admissions\Actions;

use App\Domain\Admissions\Models\AdmissionDecision;
use App\Domain\Admissions\Models\AdmissionLead;
use App\Domain\Governance\AuditLogger;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records the final admissions decision for a lead and advances its stage
 * to `decided`. Restricted to admin/director/academic_manager — enforced by
 * the controller (same convention as the rest of this module's actions),
 * not here. A lead can be decided exactly once: a second call is rejected
 * rather than silently overwriting the first decision, matching the
 * DB-unique constraint on admission_decisions.admission_lead_id.
 */
class RecordDecision
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function handle(AdmissionLead $lead, User $actor, string $decision, ?string $notes): AdmissionDecision
    {
        if (! in_array($decision, AdmissionDecision::DECISIONS, true)) {
            throw ValidationException::withMessages([
                'decision' => 'უცნობი გადაწყვეტილება.',
            ]);
        }

        return DB::transaction(function () use ($lead, $actor, $decision, $notes) {
            /** @var AdmissionLead $locked */
            $locked = AdmissionLead::query()->whereKey($lead->id)->lockForUpdate()->firstOrFail();

            if (AdmissionDecision::query()->where('admission_lead_id', $locked->id)->exists()) {
                throw ValidationException::withMessages([
                    'decision' => 'ამ განაცხადზე გადაწყვეტილება უკვე დაფიქსირებულია.',
                ]);
            }

            $decidedAt = Carbon::now();

            $record = new AdmissionDecision([
                'admission_lead_id' => $locked->id,
                'decision' => $decision,
                'decided_by' => $actor->id,
                'decided_at' => $decidedAt,
                'notes' => $notes,
            ]);
            $record->tenant_id = $locked->tenant_id;
            $record->save();

            $locked->stage = AdmissionLead::STAGE_DECIDED;
            $locked->save();

            $this->auditLogger->record($locked->tenant_id, 'admission_lead.decided', $locked, $actor->id, [
                'decision' => $decision,
                'admission_decision_id' => $record->id,
            ]);

            return $record;
        });
    }
}
