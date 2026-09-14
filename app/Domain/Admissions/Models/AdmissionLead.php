<?php

namespace App\Domain\Admissions\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A short public interest form submission (docs/02 section 5.1) — NOT the
 * full admission application, which is a separate, more protected flow.
 *
 * `stage` tracks this lead's position in the admissions pipeline
 * (CLAUDE-PLATFORM-MODULES.md): new -> contacted -> visit_scheduled ->
 * documents_submitted -> decided. This replaces an earlier, broader
 * placeholder stage set (visit/application/review/offer/enrolled/closed)
 * that predated any real pipeline behaviour beyond lead capture — nothing
 * outside this model referenced those values, so narrowing them here is
 * safe. Stages move forward-only (see AdvanceLeadStage); `decided` is
 * reachable only through RecordDecision, never a plain stage advance,
 * since it must always be backed by an admission_decisions row.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $guardian_name
 * @property string $contact_method
 * @property string $contact_value
 * @property string|null $desired_grade
 * @property string|null $preferred_date
 * @property bool $consent_given
 * @property string $stage
 */
#[Fillable([
    'guardian_name', 'contact_method', 'contact_value', 'desired_grade',
    'preferred_date', 'consent_given', 'stage', 'duplicate_of_lead_id', 'duplicate_of_checked_at',
])]
class AdmissionLead extends Model
{
    use BelongsToTenant;

    public const STAGE_NEW = 'new';

    public const STAGE_CONTACTED = 'contacted';

    public const STAGE_VISIT_SCHEDULED = 'visit_scheduled';

    public const STAGE_DOCUMENTS_SUBMITTED = 'documents_submitted';

    public const STAGE_DECIDED = 'decided';

    /**
     * Pipeline order, earliest first. Index position is what "forward-only"
     * is checked against — see AdvanceLeadStage.
     *
     * @var array<int, string>
     */
    public const STAGES = [
        self::STAGE_NEW,
        self::STAGE_CONTACTED,
        self::STAGE_VISIT_SCHEDULED,
        self::STAGE_DOCUMENTS_SUBMITTED,
        self::STAGE_DECIDED,
    ];

    protected function casts(): array
    {
        return [
            'consent_given' => 'boolean',
            'duplicate_of_checked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_lead_id');
    }

    /**
     * @return HasMany<AdmissionAppointment, $this>
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(AdmissionAppointment::class)->orderBy('scheduled_at');
    }

    /**
     * @return HasOne<AdmissionDecision, $this>
     */
    public function decision(): HasOne
    {
        return $this->hasOne(AdmissionDecision::class);
    }

    /**
     * This lead's index in the STAGES pipeline, or null for an unrecognised
     * stage value (defensive — every value written through this app is one
     * of the STAGES constants).
     */
    public function stageIndex(): ?int
    {
        $index = array_search($this->stage, self::STAGES, true);

        return $index === false ? null : $index;
    }
}
