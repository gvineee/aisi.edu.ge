<?php

namespace App\Domain\Learning\Models;

use App\Domain\Academics\Models\Student;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A guardian's note explaining a pupil's absence over a date range.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $student_id
 * @property int $submitted_by
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property string $reason
 * @property string $status
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $review_note
 * @property Carbon $created_at
 */
#[Fillable(['student_id', 'submitted_by', 'starts_on', 'ends_on', 'reason', 'status'])]
class AbsenceNote extends Model
{
    use BelongsToTenant;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'reviewed_at' => 'datetime',
        ];
    }

    /** Whether an approved note covers this pupil on this school day. */
    public static function approvedCovers(int $tenantId, int $studentId, string $date): bool
    {
        return self::query()
            ->where('tenant_id', $tenantId)
            ->where('student_id', $studentId)
            ->where('status', self::STATUS_APPROVED)
            ->whereDate('starts_on', '<=', $date)
            ->whereDate('ends_on', '>=', $date)
            ->exists();
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
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }
}
