<?php

namespace App\Domain\Timetable\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One stretch at work: in at `clock_in_at`, out at `clock_out_at` (null
 * while still in). Times are UTC; `work_date` is the school-local day.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property Carbon $work_date
 * @property Carbon $clock_in_at
 * @property Carbon|null $clock_out_at
 * @property string $source
 * @property string|null $note
 * @property int|null $corrected_by
 * @property Carbon|null $corrected_at
 * @property string|null $correction_reason
 * @property-read User $user
 */
#[Fillable(['user_id', 'work_date', 'clock_in_at', 'clock_out_at', 'source', 'note', 'corrected_by', 'corrected_at', 'correction_reason'])]
class StaffTimeEntry extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['work_date' => 'date', 'clock_in_at' => 'datetime', 'clock_out_at' => 'datetime', 'corrected_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function minutes(): ?int
    {
        return $this->clock_out_at === null ? null : (int) $this->clock_in_at->diffInMinutes($this->clock_out_at);
    }
}
