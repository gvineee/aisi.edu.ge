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
 * One row per (assignment, student) — enforced by a DB unique constraint,
 * the same "one row per occurrence" pattern AttendanceRecord uses.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $assignment_id
 * @property int $student_id
 * @property Carbon|null $submitted_at
 * @property string|null $file_path
 * @property string|null $text_response
 * @property bool $is_late
 * @property int|null $score
 * @property string|null $feedback
 * @property string $status
 * @property int|null $graded_by
 * @property Carbon|null $graded_at
 */
#[Fillable([
    'assignment_id', 'student_id', 'submitted_at', 'file_path', 'text_response',
    'is_late', 'score', 'feedback', 'status', 'graded_by', 'graded_at',
])]
class AssignmentSubmission extends Model
{
    use BelongsToTenant;

    public const STATUS_NOT_SUBMITTED = 'not_submitted';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_GRADED = 'graded';

    public const STATUS_RETURNED = 'returned';

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'graded_at' => 'datetime',
            'is_late' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Assignment, $this>
     */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
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
    public function grader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'graded_by');
    }
}
