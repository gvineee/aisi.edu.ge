<?php

namespace App\Domain\Content\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A single uploaded media library asset (image or PDF) an editor can embed
 * into a Page's blocks or a Post's body/cover image. Stored on the 'public'
 * disk — the same disk brand logo/hero assets already use — because CMS
 * media is meant to end up on a published public page; the *page/post
 * content* referencing it is still gated behind portal auth until publish
 * (docs/08-content-migration.md §6.9's asset classification note).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $disk
 * @property string $path
 * @property string $mime
 * @property int $size
 * @property string $original_filename
 * @property string|null $alt_text
 * @property int|null $created_by
 */
#[Fillable(['disk', 'path', 'mime', 'size', 'original_filename', 'alt_text', 'created_by'])]
class Media extends Model
{
    use BelongsToTenant;

    protected $table = 'media';

    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
