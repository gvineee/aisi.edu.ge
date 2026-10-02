<?php

namespace App\Domain\Portfolio\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Record of one portfolio export: which published items it contained and
 * the checksum of the exported content.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $student_id
 * @property int $generated_by
 * @property array<int, int> $item_ids
 * @property string $checksum
 * @property Carbon $generated_at
 */
#[Fillable(['student_id', 'generated_by', 'item_ids', 'checksum', 'generated_at'])]
class PortfolioExport extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'item_ids' => 'array',
            'generated_at' => 'datetime',
        ];
    }
}
