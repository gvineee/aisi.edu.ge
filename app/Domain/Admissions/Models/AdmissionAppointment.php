<?php

namespace App\Domain\Admissions\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A scheduled visit/interview for one admission lead (CLAUDE-PLATFORM-MODULES.md
 * admissions pipeline). Purely a record of what was scheduled — it does not
 * itself move the lead's `stage`; an admin/director/academic_manager advances
 * the stage explicitly via AdvanceLeadStage once the visit is confirmed, so
 * multiple appointments (e.g. a rescheduled or follow-up visit) can be
 * recorded against the same lead without re-triggering a stage transition.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $admission_lead_id
 * @property Carbon $scheduled_at
 * @property string|null $notes
 * @property int|null $created_by
 */
#[Fillable(['admission_lead_id', 'scheduled_at', 'notes', 'created_by'])]
class AdmissionAppointment extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
