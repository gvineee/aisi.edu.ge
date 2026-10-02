<?php

namespace App\Domain\Assessment\Models;

use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\Student;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One student's report card (ტაბელი) for one assessment period.
 *
 * Workflow (CLAUDE-PLATFORM-MODULES §6): draft → teacher_complete →
 * homeroom_review → director_approved → published. Families only ever see
 * a ReportCardPublication — a frozen snapshot — never the working card,
 * so a correction (new revision) never exposes half-edited data.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $student_id
 * @property int $school_class_id
 * @property int $assessment_period_id
 * @property string $status
 * @property int $revision
 * @property int $created_by
 * @property-read int|null $completed_subjects_count withCount alias used by the report card overview
 */
#[Fillable(['student_id', 'school_class_id', 'assessment_period_id', 'status', 'revision', 'created_by'])]
class ReportCard extends Model
{
    use BelongsToTenant;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_TEACHER_COMPLETE = 'teacher_complete';

    public const STATUS_HOMEROOM_REVIEW = 'homeroom_review';

    public const STATUS_DIRECTOR_APPROVED = 'director_approved';

    public const STATUS_PUBLISHED = 'published';

    protected function casts(): array
    {
        return [
            'revision' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * @return BelongsTo<SchoolClass, $this>
     */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }

    /**
     * @return BelongsTo<AssessmentPeriod, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(AssessmentPeriod::class, 'assessment_period_id');
    }

    /**
     * @return HasMany<ReportCardSubject, $this>
     */
    public function subjects(): HasMany
    {
        return $this->hasMany(ReportCardSubject::class)->orderBy('subject_label');
    }

    /**
     * @return HasMany<ReportCardComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(ReportCardComment::class)->orderByDesc('id');
    }

    /**
     * @return HasMany<ReportCardApproval, $this>
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(ReportCardApproval::class)->orderByDesc('id');
    }

    /**
     * @return HasMany<ReportCardPublication, $this>
     */
    public function publications(): HasMany
    {
        return $this->hasMany(ReportCardPublication::class)->orderByDesc('revision');
    }

    public function latestComment(string $kind): ?ReportCardComment
    {
        return $this->comments
            ->where('revision', $this->revision)
            ->firstWhere('kind', $kind);
    }
}
