<?php

namespace App\Domain\Admissions\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A school visit time families can book in the public visit form.
 * booked_count only changes under a row lock (BookSchoolVisit).
 *
 * @property int $id
 * @property int $tenant_id
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property int $capacity
 * @property int $booked_count
 * @property string|null $label
 * @property string|null $location
 * @property string $status
 * @property string|null $cancel_reason
 * @property Carbon|null $cancelled_at
 * @property int $created_by
 */
#[Fillable(['starts_at', 'ends_at', 'capacity', 'booked_count', 'label', 'location', 'status', 'cancel_reason', 'cancelled_at', 'created_by'])]
class AdmissionVisitSlot extends Model
{
    use BelongsToTenant;

    public const STATUS_OPEN = 'open';

    public const STATUS_CANCELLED = 'cancelled';

    /** Bookings close this many minutes before the visit starts. */
    public const BOOKING_CUTOFF_MINUTES = 120;

    /** How far ahead the public form offers visits. */
    public const BOOKING_HORIZON_DAYS = 90;

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'capacity' => 'integer',
            'booked_count' => 'integer',
            'cancelled_at' => 'datetime',
        ];
    }

    public function remaining(): int
    {
        return max(0, $this->capacity - $this->booked_count);
    }

    public function isBookable(): bool
    {
        return $this->status === self::STATUS_OPEN
            && $this->remaining() > 0
            && $this->starts_at->gt(Carbon::now()->addMinutes(self::BOOKING_CUTOFF_MINUTES));
    }

    /**
     * @return HasMany<AdmissionVisitBooking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(AdmissionVisitBooking::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
