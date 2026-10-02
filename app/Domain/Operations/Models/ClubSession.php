<?php

namespace App\Domain\Operations\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A weekly meeting time of a club.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $club_id
 * @property int $day_of_week
 * @property string $starts_at
 * @property string $ends_at
 * @property string|null $location
 */
#[Fillable(['club_id', 'day_of_week', 'starts_at', 'ends_at', 'location'])]
class ClubSession extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
        ];
    }
}
