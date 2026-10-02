<?php

namespace App\Domain\Assessment\Models;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A grading term (e.g. a semester). Once closed, results inside it can no
 * longer be edited and new assessments cannot be added; only a
 * management role can reopen it, with a recorded reason.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $academic_year_id
 * @property string $name
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property string $status
 * @property Carbon|null $closed_at
 * @property int|null $closed_by
 */
#[Fillable(['academic_year_id', 'name', 'starts_on', 'ends_on', 'status', 'closed_at', 'closed_by'])]
class AssessmentPeriod extends Model
{
    use BelongsToTenant;

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AcademicYear, $this>
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }
}
