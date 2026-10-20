<?php

namespace App\Domain\Library\Models;

use App\Domain\Academics\Models\Student;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A copy lent to a student or a staff member. active_key holds the copy id
 * while the loan is active (unique), so a copy can never be in two active
 * loans, even under concurrent requests.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $library_copy_id
 * @property int|null $student_id
 * @property int|null $user_id
 * @property Carbon $due_on
 * @property int $renewals
 * @property string $status
 * @property int|null $active_key
 * @property Carbon|null $returned_at
 * @property int $lent_by
 * @property int|null $closed_by
 * @property Carbon|null $reminder_sent_at
 * @property Carbon|null $created_at
 * @property-read LibraryCopy $copy
 * @property-read Student|null $student
 * @property-read User|null $user
 */
#[Fillable(['library_copy_id', 'student_id', 'user_id', 'due_on', 'renewals', 'status', 'active_key', 'returned_at', 'lent_by', 'closed_by', 'reminder_sent_at'])]
class LibraryLoan extends Model
{
    use BelongsToTenant;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_RETURNED = 'returned';

    public const STATUS_LOST = 'lost';

    public const MAX_RENEWALS = 2;

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'renewals' => 'integer',
            'active_key' => 'integer',
            'returned_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
        ];
    }

    /** $today is a Y-m-d date in the school's timezone. */
    public function isOverdue(string $today): bool
    {
        return $this->status === self::STATUS_ACTIVE && $this->due_on->toDateString() < $today;
    }

    public function borrowerName(): string
    {
        return $this->student?->fullName() ?? $this->user->name ?? '—';
    }

    /**
     * @return BelongsTo<LibraryCopy, $this>
     */
    public function copy(): BelongsTo
    {
        return $this->belongsTo(LibraryCopy::class, 'library_copy_id');
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
