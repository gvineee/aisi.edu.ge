<?php

namespace App\Domain\Content\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Tracks the idempotency key and conflict state for one imported legacy
 * WordPress record (docs/08-content-migration.md §6). Kept separate from
 * Page/Post so those models stay free of importer-specific columns.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $source_system
 * @property string $source_key
 * @property int|null $source_id
 * @property string $source_type
 * @property string $source_url
 * @property string|null $importable_type
 * @property int|null $importable_id
 * @property string $import_batch_id
 * @property string|null $source_checksum
 * @property string|null $local_checksum
 * @property string $review_status
 * @property CarbonImmutable|null $imported_at
 */
#[Fillable([
    'source_system', 'source_key', 'source_id', 'source_type', 'source_url',
    'importable_type', 'importable_id', 'import_batch_id',
    'source_checksum', 'local_checksum', 'review_status', 'imported_at',
])]
class ContentImportRecord extends Model
{
    use BelongsToTenant;

    public const STATUS_PENDING = 'pending';

    public const STATUS_IMPORTED = 'imported';

    public const STATUS_MERGED = 'merged';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUS_TEMPLATE_REVIEW = 'template_review';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_CONFLICT = 'conflict';

    protected function casts(): array
    {
        return [
            'imported_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function importable(): MorphTo
    {
        return $this->morphTo();
    }
}
