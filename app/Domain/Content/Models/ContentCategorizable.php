<?php

namespace App\Domain\Content\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Places one page or post into a category (one category per item), with
 * a section and an order inside it.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $content_category_id
 * @property string $categorizable_type
 * @property int $categorizable_id
 * @property string|null $section
 * @property int $position
 * @property string|null $placed_by_source
 */
#[Fillable(['content_category_id', 'categorizable_type', 'categorizable_id', 'section', 'position', 'placed_by_source'])]
class ContentCategorizable extends Model
{
    use BelongsToTenant;

    /**
     * @return BelongsTo<ContentCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ContentCategory::class, 'content_category_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function categorizable(): MorphTo
    {
        return $this->morphTo();
    }
}
