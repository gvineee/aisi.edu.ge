<?php

namespace App\Domain\Admissions\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The final admissions decision for one lead (CLAUDE-PLATFORM-MODULES.md
 * admissions pipeline) — at most one per lead (DB-unique on
 * admission_lead_id), recorded only by RecordDecision, which is restricted
 * to admin/director/academic_manager. Recording a decision also advances the
 * lead's `stage` to `decided`.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $admission_lead_id
 * @property string $decision
 * @property int|null $decided_by
 * @property Carbon $decided_at
 * @property string|null $notes
 */
#[Fillable(['admission_lead_id', 'decision', 'decided_by', 'decided_at', 'notes'])]
class AdmissionDecision extends Model
{
    use BelongsToTenant;

    public const DECISION_ACCEPTED = 'accepted';

    public const DECISION_DECLINED = 'declined';

    public const DECISION_WAITLISTED = 'waitlisted';

    /**
     * @var array<int, string>
     */
    public const DECISIONS = [self::DECISION_ACCEPTED, self::DECISION_DECLINED, self::DECISION_WAITLISTED];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AdmissionLead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(AdmissionLead::class, 'admission_lead_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
