<?php

namespace App\Domain\Assessment\Models;

use App\Domain\Academics\Models\TeacherAssignment;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One subject line on a report card, owned by the teacher of that
 * `teacher_assignment`. `final_mark` is entered by the teacher (the
 * school's grading policy decides what it means); the count/average are
 * an informational summary of the period's published assessments.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $report_card_id
 * @property int $teacher_assignment_id
 * @property string $subject_label
 * @property int $assessments_count
 * @property string|null $average_percent
 * @property string|null $final_mark
 * @property string|null $teacher_comment
 * @property Carbon|null $completed_at
 * @property int|null $completed_by
 */
#[Fillable([
    'report_card_id', 'teacher_assignment_id', 'subject_label', 'assessments_count',
    'average_percent', 'final_mark', 'teacher_comment', 'completed_at', 'completed_by',
])]
class ReportCardSubject extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'assessments_count' => 'integer',
            'average_percent' => 'decimal:2',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ReportCard, $this>
     */
    public function reportCard(): BelongsTo
    {
        return $this->belongsTo(ReportCard::class);
    }

    /**
     * @return BelongsTo<TeacherAssignment, $this>
     */
    public function teacherAssignment(): BelongsTo
    {
        return $this->belongsTo(TeacherAssignment::class);
    }

    public function isComplete(): bool
    {
        return $this->completed_at !== null;
    }
}
