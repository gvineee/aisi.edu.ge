<?php

namespace App\Domain\Assessment\Models;

use App\Domain\Academics\Models\TeacherAssignment;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One graded piece of work (test, project, oral answer) for a teacher's own
 * class + subject. Results stay invisible to students/guardians until the
 * assessment is published (the "unpublished grade" critical case).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $teacher_assignment_id
 * @property int $assessment_period_id
 * @property int|null $assessment_category_id
 * @property int|null $grade_scale_id
 * @property string $title
 * @property Carbon $assessed_on
 * @property string $max_score
 * @property string $status
 * @property Carbon|null $published_at
 * @property int $created_by
 */
#[Fillable([
    'teacher_assignment_id', 'assessment_period_id', 'assessment_category_id', 'grade_scale_id',
    'title', 'assessed_on', 'max_score', 'status', 'published_at', 'created_by',
])]
class Assessment extends Model
{
    use BelongsToTenant;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    protected function casts(): array
    {
        return [
            'assessed_on' => 'date',
            'max_score' => 'decimal:2',
            'published_at' => 'datetime',
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
     * @return BelongsTo<AssessmentPeriod, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(AssessmentPeriod::class, 'assessment_period_id');
    }

    /**
     * @return BelongsTo<AssessmentCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(AssessmentCategory::class, 'assessment_category_id');
    }

    /**
     * @return BelongsTo<GradeScale, $this>
     */
    public function gradeScale(): BelongsTo
    {
        return $this->belongsTo(GradeScale::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<AssessmentResult, $this>
     */
    public function results(): HasMany
    {
        return $this->hasMany(AssessmentResult::class);
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }
}
