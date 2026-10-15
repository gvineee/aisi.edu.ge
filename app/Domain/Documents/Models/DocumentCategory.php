<?php

namespace App\Domain\Documents\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Tenant-defined document category (e.g. "შრომითი ხელშეკრულება",
 * "ბრძანება", "ოქმი"), managed by director/admin. An archived category stays
 * on existing documents but is no longer offered for new ones.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property int $sort_order
 * @property Carbon|null $archived_at
 */
#[Fillable(['name', 'sort_order'])]
class DocumentCategory extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'category_id');
    }
}
