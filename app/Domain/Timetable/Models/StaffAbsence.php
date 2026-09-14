<?php

namespace App\Domain\Timetable\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A teacher reported absent for a date range. `starts_on`/`ends_on` are
 * calendar dates in the school's own timezone, same reasoning as other
 * date-only academic fields (docs/02 §5.3). Recording one does not by
 * itself cover any lesson — an admin/academic_manager/director still has to
 * assign a SubstitutionAssignment per affected lesson+date; `status` only
 * tracks whether that has happened yet.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property string|null $reason
 * @property string $status
 * @property int|null $created_by
 */
#[Fillable(['user_id', 'starts_on', 'ends_on', 'reason', 'status', 'created_by'])]
class StaffAbsence extends Model
{
    use BelongsToTenant;

    public const STATUS_REPORTED = 'reported';

    public const STATUS_COVERED = 'covered';

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<SubstitutionAssignment, $this>
     */
    public function substitutionAssignments(): HasMany
    {
        return $this->hasMany(SubstitutionAssignment::class, 'absence_id');
    }
}
