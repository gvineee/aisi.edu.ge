<?php

namespace App\Domain\Assessment\Models;

use App\Domain\Academics\Models\Student;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $assessment_id
 * @property int $student_id
 * @property string $raw_score
 * @property string $normalized_percent
 * @property string|null $final_grade
 * @property string|null $comment
 * @property int $version
 * @property int $recorded_by
 */
#[Fillable(['assessment_id', 'student_id', 'raw_score', 'normalized_percent', 'final_grade', 'comment', 'version', 'recorded_by'])]
class AssessmentResult extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'raw_score' => 'decimal:2',
            'normalized_percent' => 'decimal:2',
            'version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Assessment, $this>
     */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * @return HasMany<AssessmentResultRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(AssessmentResultRevision::class)->orderByDesc('version');
    }
}
