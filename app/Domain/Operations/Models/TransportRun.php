<?php

namespace App\Domain\Operations\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One day of one route, with its reported status.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $transport_route_id
 * @property Carbon $run_date
 * @property string $status
 * @property string|null $note
 * @property int|null $updated_by
 */
#[Fillable(['transport_route_id', 'run_date', 'status', 'note', 'updated_by'])]
class TransportRun extends Model
{
    use BelongsToTenant;

    /**
     * @var array<int, string>
     */
    public const STATUSES = ['planned', 'departed', 'delayed', 'completed', 'cancelled'];

    protected function casts(): array
    {
        return [
            'run_date' => 'date',
        ];
    }
}
