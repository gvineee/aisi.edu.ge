<?php

namespace App\Domain\Learning\Models;

use App\Domain\Academics\Models\TeacherAssignment;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A teacher's homework/task for one `teacher_assignment` (class + subject +
 * teacher) — the Assignments & Submissions module (Phase 2 homework
 * workflow). Stays invisible to students/guardians until published, the
 * same draft/published split PortfolioItem uses.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $teacher_assignment_id
 * @property string $title
 * @property string|null $description
 * @property Carbon|null $due_at
 * @property int|null $max_score
 * @property string $status
 * @property int $created_by
 */
#[Fillable(['teacher_assignment_id', 'title', 'description', 'due_at', 'max_score', 'status', 'created_by'])]
class Assignment extends Model
{
    use BelongsToTenant;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<TeacherAssignment, $this>
     */
    public function teacherAssignment(): BelongsTo
    {
        return $this->belongsTo(TeacherAssignment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<AssignmentSubmission, $this>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(AssignmentSubmission::class);
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }
}
