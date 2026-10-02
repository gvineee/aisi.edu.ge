<?php

namespace App\Domain\Operations\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An after-school club with a fixed capacity and an ordered waitlist.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string|null $description
 * @property int|null $leader_id
 * @property int $capacity
 * @property int|null $fee_minor
 * @property string $currency
 * @property bool $is_open
 */
#[Fillable(['name', 'description', 'leader_id', 'capacity', 'fee_minor', 'currency', 'is_open'])]
class Club extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'fee_minor' => 'integer',
            'is_open' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function leader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'leader_id');
    }

    /**
     * @return HasMany<ClubSession, $this>
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(ClubSession::class);
    }

    /**
     * @return HasMany<ClubEnrollment, $this>
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(ClubEnrollment::class);
    }

    /**
     * @return HasMany<ClubWaitlistEntry, $this>
     */
    public function waitlist(): HasMany
    {
        return $this->hasMany(ClubWaitlistEntry::class);
    }
}
