<?php

namespace App\Domain\Timetable\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A teacher asking for their own lessons to be covered over a date range.
 * Approval (admin/academic_manager/director) records the StaffAbsence that
 * the existing coverage worklist is built from; the request itself never
 * assigns anyone.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $requester_id
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property string|null $reason
 * @property string $status
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_note
 * @property int|null $absence_id
 */
#[Fillable(['requester_id', 'starts_on', 'ends_on', 'reason', 'status', 'decided_by', 'decided_at', 'decision_note', 'absence_id'])]
class SubstitutionRequest extends Model
{
    use BelongsToTenant;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_WITHDRAWN = 'withdrawn';

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /**
     * @return BelongsTo<StaffAbsence, $this>
     */
    public function absence(): BelongsTo
    {
        return $this->belongsTo(StaffAbsence::class, 'absence_id');
    }
}
