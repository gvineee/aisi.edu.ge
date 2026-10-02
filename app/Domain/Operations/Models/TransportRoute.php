<?php

namespace App\Domain\Operations\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A planned school bus route. Status data only — no live GPS without a provider.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string $direction
 * @property string|null $vehicle_label
 * @property bool $is_active
 */
#[Fillable(['name', 'direction', 'vehicle_label', 'is_active'])]
class TransportRoute extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<TransportStop, $this>
     */
    public function stops(): HasMany
    {
        return $this->hasMany(TransportStop::class);
    }

    /**
     * @return HasMany<TransportRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(TransportRun::class);
    }
}
