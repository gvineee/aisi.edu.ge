<?php

namespace App\Domain\Operations\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One version of the menu for one day. Publishing a new version supersedes the previous one.
 *
 * @property int $id
 * @property int $tenant_id
 * @property Carbon $served_on
 * @property int $version
 * @property string $status
 * @property Carbon|null $published_at
 * @property int|null $published_by
 */
#[Fillable(['served_on', 'version', 'status', 'published_at', 'published_by'])]
class MealMenu extends Model
{
    use BelongsToTenant;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_SUPERSEDED = 'superseded';

    protected function casts(): array
    {
        return [
            'served_on' => 'date',
            'version' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<MealItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(MealItem::class);
    }
}
