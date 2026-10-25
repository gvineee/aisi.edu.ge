<?php

namespace App\Domain\Integrations\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Pins one source record (tenant + source + type + year scope + key) to the
 * row it created, so a re-run updates instead of duplicating.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $source
 * @property string $entity_type
 * @property int $scope_id
 * @property string $source_key
 * @property string $target_type
 * @property int $target_id
 * @property string $source_hash
 * @property Carbon $synced_at
 * @property int|null $first_batch_id
 * @property int|null $last_batch_id
 */
#[Fillable(['tenant_id', 'source', 'entity_type', 'scope_id', 'source_key', 'target_type', 'target_id', 'source_hash', 'synced_at', 'first_batch_id', 'last_batch_id'])]
class ImportIdentityMap extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'synced_at' => 'datetime',
        ];
    }
}
