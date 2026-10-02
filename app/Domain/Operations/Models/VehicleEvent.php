<?php

namespace App\Domain\Operations\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Append-only status report for a run.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $transport_run_id
 * @property string $status
 * @property string|null $note
 * @property int $recorded_by
 * @property Carbon|null $created_at
 */
#[Fillable(['transport_run_id', 'status', 'note', 'recorded_by'])]
class VehicleEvent extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;
}
