<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Academics\Models\Student;
use App\Domain\Tenancy\Actions\DecideEnrollmentVerificationRequest;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A self-service claim by a just-registered account ("I am this student" /
 * "I am this student's guardian"), submitted with the identity the school's
 * roster already knows the student by (national ID, or a name fallback).
 * Never grants access on its own — {@see DecideEnrollmentVerificationRequest}
 * is the only place a `pending` request becomes real portal access, and only
 * an admin/director may call it.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property string $requested_role
 * @property string|null $submitted_national_id
 * @property string $submitted_first_name
 * @property string $submitted_last_name
 * @property int|null $matched_student_id
 * @property string $status
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $rejection_reason
 */
#[Fillable([
    'user_id', 'requested_role', 'submitted_national_id', 'submitted_first_name', 'submitted_last_name',
    'matched_student_id', 'status', 'reviewed_by', 'reviewed_at', 'rejection_reason',
])]
class EnrollmentVerificationRequest extends Model
{
    use BelongsToTenant;

    public const ROLE_STUDENT = 'student';

    public const ROLE_GUARDIAN = 'guardian';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function submittedFullName(): string
    {
        return trim("{$this->submitted_first_name} {$this->submitted_last_name}");
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function matchedStudent(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'matched_student_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
