<?php

namespace App\Domain\Integrations\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One run of a school-data import, dry-run or applied.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $source
 * @property string $type
 * @property int|null $academic_year_id
 * @property string $mode
 * @property string $status
 * @property string $file_name
 * @property string $file_sha256
 * @property array<string, mixed>|null $context
 * @property array<string, int>|null $counts
 * @property int|null $created_by
 */
#[Fillable(['tenant_id', 'source', 'type', 'academic_year_id', 'mode', 'status', 'file_name', 'file_sha256', 'context', 'counts', 'created_by'])]
class ImportBatch extends Model
{
    use BelongsToTenant;

    public const MODE_DRY_RUN = 'dry_run';

    public const MODE_APPLY = 'apply';

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'counts' => 'array',
        ];
    }

    /**
     * @return HasMany<ImportIssue, $this>
     */
    public function issues(): HasMany
    {
        return $this->hasMany(ImportIssue::class);
    }
}
