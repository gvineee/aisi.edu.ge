<?php

namespace App\Domain\Communications\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A parent–teacher meeting time a teacher offers.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $teacher_id
 * @property Carbon $starts_at
 * @property int $duration_minutes
 * @property string|null $location
 * @property int $capacity
 * @property string $status
 */
#[Fillable(['teacher_id', 'starts_at', 'duration_minutes', 'location', 'capacity', 'status'])]
class AppointmentSlot extends Model
{
    use BelongsToTenant;

    public const STATUS_OPEN = 'open';

    public const STATUS_CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'duration_minutes' => 'integer',
            'capacity' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    /**
     * @return HasMany<AppointmentBooking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(AppointmentBooking::class);
    }

    /**
     * @return HasMany<AppointmentBooking, $this>
     */
    public function activeBookings(): HasMany
    {
        return $this->bookings()->where('status', AppointmentBooking::STATUS_BOOKED);
    }
}
