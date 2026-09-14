<?php

namespace App\Domain\Admissions\Actions;

use App\Domain\Admissions\Models\AdmissionLead;
use App\Domain\Governance\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves an admission lead forward one or more steps in its pipeline
 * (AdmissionLead::STAGES). Forward-only, on purpose and kept simple: a
 * target stage must sit strictly after the lead's current stage in that
 * fixed order, so this rejects both a no-op ("advance" to the same stage)
 * and any backward move — there is no "reset" action in this pass, a
 * backward correction is an operator process question, not a UI button.
 *
 * `decided` is deliberately not a valid target here — it is reachable only
 * through RecordDecision, which also creates the admission_decisions row a
 * `decided` lead must always have.
 */
class AdvanceLeadStage
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function handle(AdmissionLead $lead, User $actor, string $targetStage): AdmissionLead
    {
        if ($targetStage === AdmissionLead::STAGE_DECIDED) {
            throw ValidationException::withMessages([
                'stage' => 'ეტაპზე „გადაწყვეტილია“ გადასვლა მხოლოდ გადაწყვეტილების დაფიქსირებით ხდება.',
            ]);
        }

        $targetIndex = array_search($targetStage, AdmissionLead::STAGES, true);

        if ($targetIndex === false) {
            throw ValidationException::withMessages([
                'stage' => 'უცნობი ეტაპი.',
            ]);
        }

        return DB::transaction(function () use ($lead, $actor, $targetStage, $targetIndex) {
            /** @var AdmissionLead $locked */
            $locked = AdmissionLead::query()->whereKey($lead->id)->lockForUpdate()->firstOrFail();

            $currentIndex = $locked->stageIndex();

            if ($currentIndex === null || $targetIndex <= $currentIndex) {
                throw ValidationException::withMessages([
                    'stage' => 'ეტაპის შეცვლა შესაძლებელია მხოლოდ წინსვლით, მიმდინარე ეტაპის შემდეგ.',
                ]);
            }

            $fromStage = $locked->stage;
            $locked->stage = $targetStage;
            $locked->save();

            $this->auditLogger->record($locked->tenant_id, 'admission_lead.stage_advanced', $locked, $actor->id, [
                'from' => $fromStage,
                'to' => $targetStage,
            ]);

            return $locked;
        });
    }
}
