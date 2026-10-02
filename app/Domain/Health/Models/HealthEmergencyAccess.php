<?php

namespace App\Domain\Health\Models;

use App\Domain\Academics\Models\Student;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Time-bound "break glass" access by a staff member without a health
 * grant. Requires a reason, expires on its own, and stays in the review
 * queue until someone with health.audit.view reviews it.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $student_id
 * @property int $user_id
 * @property string $reason
 * @property Carbon $expires_at
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $review_note
 * @property Carbon|null $created_at
 */
#[Fillable(['student_id', 'user_id', 'reason', 'expires_at', 'reviewed_by', 'reviewed_at', 'review_note'])]
class HealthEmergencyAccess extends Model
{
    use BelongsToTenant;

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    protected function casts(): array
    {
        return [
            'reason' => 'encrypted',
            'expires_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'review_note' => 'encrypted',
        ];
    }
}
