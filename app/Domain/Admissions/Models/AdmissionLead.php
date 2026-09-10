<?php

namespace App\Domain\Admissions\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A short public interest form submission (docs/02 section 5.1) — NOT the
 * full admission application, which is a separate, more protected flow.
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

    public const STAGE_VISIT = 'visit';

    public const STAGE_APPLICATION = 'application';

    public const STAGE_REVIEW = 'review';

    public const STAGE_OFFER = 'offer';

    public const STAGE_ENROLLED = 'enrolled';

    public const STAGE_CLOSED = 'closed';

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
}
