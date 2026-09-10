<?php

namespace App\Domain\Learning\Models;

use App\Domain\Academics\Models\Student;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Timetable\Models\Lesson;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One record per student per actual lesson occurrence (docs/02 §5.4
 * invariant, enforced by a DB unique constraint too).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $lesson_id
 * @property int $student_id
 * @property Carbon $occurred_on
 * @property string $status
 * @property string|null $comment
 * @property int $marked_by
 * @property int|null $updated_by
 */
#[Fillable(['lesson_id', 'student_id', 'occurred_on', 'status', 'comment', 'marked_by', 'updated_by'])]
class AttendanceRecord extends Model
{
    use BelongsToTenant;

    public const STATUS_PRESENT = 'present';

    public const STATUS_LATE = 'late';

    public const STATUS_ABSENT = 'absent';

    public const STATUS_EXCUSED = 'excused';

    protected function casts(): array
    {
        return [
            'occurred_on' => 'date',
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
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function markedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by');
    }
}
