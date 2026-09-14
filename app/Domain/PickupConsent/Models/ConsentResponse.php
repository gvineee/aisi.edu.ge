<?php

namespace App\Domain\PickupConsent\Models;

use App\Domain\Academics\Models\Student;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A guardian's answer to a ConsentForm on behalf of one child. Unique on
 * (consent_form_id, student_id) — RespondToConsent updates this row on a
 * second response instead of creating a duplicate.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $consent_form_id
 * @property int $student_id
 * @property int $guardian_id
 * @property Carbon|null $responded_at
 * @property bool|null $granted
 */
#[Fillable(['consent_form_id', 'student_id', 'guardian_id', 'responded_at', 'granted'])]
class ConsentResponse extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'responded_at' => 'datetime',
            'granted' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<ConsentForm, $this>
     */
    public function consentForm(): BelongsTo
    {
        return $this->belongsTo(ConsentForm::class);
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function guardian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'guardian_id');
    }
}
