<?php

namespace App\Domain\Content\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Immutable snapshot of a Post at the moment it was saved — the basis for
 * "revert to this version" (mirrors PageRevision; see docs/02 section 5.1).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $post_id
 * @property string $title
 * @property string|null $excerpt
 * @property string $body
 * @property string|null $cover_image_path
 * @property string $status
 * @property Carbon|null $created_at
 */
#[Fillable(['post_id', 'title', 'excerpt', 'body', 'cover_image_path', 'status', 'seo_title', 'seo_description', 'created_by'])]
class PostRevision extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
