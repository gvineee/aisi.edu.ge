<?php

namespace App\Domain\Content\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable snapshot of a Page at the moment it was saved — the basis for
 * "revert to this version" (see docs/02 section 5.1).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $page_id
 * @property string $title
 * @property string|null $excerpt
 * @property array<int, array<string, mixed>> $blocks
 * @property string $status
 */
#[Fillable(['page_id', 'title', 'excerpt', 'blocks', 'status', 'seo_title', 'seo_description', 'created_by'])]
class PageRevision extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'blocks' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Page, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
