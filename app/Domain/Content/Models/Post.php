<?php

namespace App\Domain\Content\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A news/story article ("აისის ამბები") — separate from Page, which is
 * for static site pages (docs/02 Content domain: pages, posts, media).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $slug
 * @property string $locale
 * @property string $title
 * @property string|null $excerpt
 * @property string $body
 * @property string|null $cover_image_path
 * @property string $status
 * @property Carbon|null $published_at
 */
#[Fillable(['slug', 'locale', 'title', 'excerpt', 'body', 'cover_image_path', 'status', 'published_at', 'seo_title', 'seo_description', 'created_by', 'updated_by'])]
class Post extends Model
{
    use BelongsToTenant;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED && $this->published_at !== null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
