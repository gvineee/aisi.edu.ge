<?php

namespace App\Domain\Academics\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One dated stay of a pupil in a class. `students.school_class_id` stays
 * the cached current class used by the existing screens.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $student_id
 * @property int $academic_year_id
 * @property int $school_class_id
 * @property Carbon $starts_on
 * @property Carbon|null $ends_on
 * @property string $status
 */
#[Fillable(['student_id', 'academic_year_id', 'school_class_id', 'starts_on', 'ends_on', 'status'])]
class StudentEnrollment extends Model
{
    use BelongsToTenant;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_TRANSFERRED = 'transferred';

    public const STATUS_LEFT = 'left';

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
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
}
