<?php

namespace App\Domain\Timetable\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One teacher covering one specific lesson occurrence on one calendar
 * `date` — lessons themselves are a recurring weekly template
 * (day_of_week + wall-clock time, see Lesson's docblock), so "who's
 * covering Monday's Math class" only makes sense per date, not per lesson
 * row alone. AssignSubstitute is the only writer and re-verifies, at
 * assignment time, that `substitute_teacher_id` has no other lesson (their
 * own schedule or another active substitution) overlapping this exact
 * date+time — see CheckSubstituteAvailability.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $lesson_id
 * @property int|null $absence_id
 * @property int $absent_teacher_id
 * @property int $substitute_teacher_id
 * @property Carbon $date
 * @property string $status
 * @property int|null $created_by
 */
#[Fillable(['lesson_id', 'absence_id', 'absent_teacher_id', 'substitute_teacher_id', 'date', 'status', 'created_by'])]
class SubstitutionAssignment extends Model
{
    use BelongsToTenant;

    public const STATUS_ASSIGNED = 'assigned';

    public const STATUS_CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Lesson, $this>
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /**
     * @return BelongsTo<StaffAbsence, $this>
     */
    public function absence(): BelongsTo
    {
        return $this->belongsTo(StaffAbsence::class, 'absence_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function absentTeacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'absent_teacher_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function substituteTeacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'substitute_teacher_id');
    }
}
