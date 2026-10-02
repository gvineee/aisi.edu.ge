<?php

namespace App\Domain\Operations\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A stop on a route with its planned time.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $transport_route_id
 * @property string $name
 * @property string $planned_time
 * @property int $sort_order
 */
#[Fillable(['transport_route_id', 'name', 'planned_time', 'sort_order'])]
class TransportStop extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }
}
