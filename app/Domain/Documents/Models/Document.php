<?php

namespace App\Domain\Documents\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * `latest_version_id`/`published_version_id` are deliberately NOT database
 * foreign keys (see the migration) — only CreateDraftDocument,
 * UploadDocumentVersion and DecideApproval are allowed to write them, and
 * always inside a transaction alongside the version row itself.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $workspace_id
 * @property int $owner_id
 * @property int|null $responsible_id
 * @property string $type
 * @property string $title
 * @property int|null $latest_version_id
 * @property int|null $published_version_id
 * @property string $status
 * @property Carbon|null $archived_at
 */
#[Fillable(['workspace_id', 'owner_id', 'responsible_id', 'type', 'title'])]
class Document extends Model
{
    use BelongsToTenant;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_IN_REVIEW = 'in_review';

    public const STATUS_CHANGES_REQUESTED = 'changes_requested';

    public const STATUS_APPROVED = 'approved';

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<DocumentWorkspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(DocumentWorkspace::class, 'workspace_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    /**
     * @return HasMany<DocumentVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->orderBy('ordinal');
    }

    /**
     * @return BelongsTo<DocumentVersion, $this>
     */
    public function latestVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'latest_version_id');
    }

    /**
     * @return BelongsTo<DocumentVersion, $this>
     */
    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'published_version_id');
    }
}
