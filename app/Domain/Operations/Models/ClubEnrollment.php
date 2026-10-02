<?php

namespace App\Domain\Operations\Models;

use App\Domain\Academics\Models\Student;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A student holding a place in a club.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $club_id
 * @property int $student_id
 * @property string $status
 * @property int $requested_by
 */
#[Fillable(['club_id', 'student_id', 'status', 'requested_by'])]
class ClubEnrollment extends Model
{
    use BelongsToTenant;

    public const STATUS_ENROLLED = 'enrolled';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @return BelongsTo<Club, $this>
     */
    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
