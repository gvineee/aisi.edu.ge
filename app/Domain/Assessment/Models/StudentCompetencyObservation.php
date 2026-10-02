<?php

namespace App\Domain\Assessment\Models;

use App\Domain\Academics\Models\Student;
use App\Domain\Academics\Models\TeacherAssignment;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A teacher's dated observation of a student's level on one competency.
 * Families see it only when `shared_with_family` is set.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $student_id
 * @property int $competency_id
 * @property int $competency_level_id
 * @property int $teacher_assignment_id
 * @property Carbon $observed_on
 * @property string|null $note
 * @property bool $shared_with_family
 * @property int $recorded_by
 */
#[Fillable(['student_id', 'competency_id', 'competency_level_id', 'teacher_assignment_id', 'observed_on', 'note', 'shared_with_family', 'recorded_by'])]
class StudentCompetencyObservation extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'observed_on' => 'date',
            'shared_with_family' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Competency, $this>
     */
    public function competency(): BelongsTo
    {
        return $this->belongsTo(Competency::class);
    }

    /**
     * @return BelongsTo<CompetencyLevel, $this>
     */
    public function level(): BelongsTo
    {
        return $this->belongsTo(CompetencyLevel::class, 'competency_level_id');
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * @return BelongsTo<TeacherAssignment, $this>
     */
    public function teacherAssignment(): BelongsTo
    {
        return $this->belongsTo(TeacherAssignment::class);
    }
}
