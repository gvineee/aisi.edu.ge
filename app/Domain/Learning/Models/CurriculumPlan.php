<?php

namespace App\Domain\Learning\Models;

use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Timetable\Models\Subject;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The year's plan for one class in one subject: an ordered list of topics.
 * The teacher drafts it, management approves it; an approved plan changes
 * only through management (or after it is reopened).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $academic_year_id
 * @property int $school_class_id
 * @property int $subject_id
 * @property string $status
 * @property string|null $notes
 * @property int|null $created_by
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property-read SchoolClass $schoolClass
 * @property-read Subject $subject
 */
#[Fillable(['academic_year_id', 'school_class_id', 'subject_id', 'status', 'notes', 'created_by', 'approved_by', 'approved_at'])]
class CurriculumPlan extends Model
{
    use BelongsToTenant;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    protected function casts(): array
    {
        return ['approved_at' => 'datetime'];
    }

    /**
     * @return HasMany<CurriculumTopic, $this>
     */
    public function topics(): HasMany
    {
        return $this->hasMany(CurriculumTopic::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return BelongsTo<SchoolClass, $this>
     */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }

    /**
     * @return BelongsTo<Subject, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
}
