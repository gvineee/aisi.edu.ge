<?php

namespace App\Domain\Content\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A teacher's public profile — its own dedicated block, separate from the
 * general CMS Page/Post models, per explicit request: a real individual
 * page per teacher plus a homepage carousel, managed from their own admin
 * screen rather than mixed in with generic pages.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $slug
 * @property string $name
 * @property string $subject
 * @property string|null $bio
 * @property string|null $photo_path
 * @property int $display_order
 * @property string $status
 * @property int|null $created_by
 * @property int|null $updated_by
 */
#[Fillable(['slug', 'name', 'subject', 'bio', 'photo_path', 'display_order', 'status', 'created_by', 'updated_by'])]
class Teacher extends Model
{
    use BelongsToTenant;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function photoUrl(): ?string
    {
        return $this->photo_path !== null ? Storage::disk('public')->url($this->photo_path) : null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
