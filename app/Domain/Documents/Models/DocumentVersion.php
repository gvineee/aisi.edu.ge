<?php

namespace App\Domain\Documents\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Immutable once created: `storage_path`/`checksum` are never rewritten for
 * an existing row. A new upload always inserts a new version with the next
 * ordinal, never updates an existing one.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $document_id
 * @property int $ordinal
 * @property string $storage_path
 * @property string $checksum
 * @property string $original_filename
 * @property string $mime
 * @property int $size
 * @property int $author_id
 * @property string $scan_state
 */
#[Fillable(['document_id', 'ordinal', 'storage_path', 'checksum', 'original_filename', 'mime', 'size', 'author_id', 'scan_state'])]
class DocumentVersion extends Model
{
    use BelongsToTenant;

    public const SCAN_CLEAN = 'clean';

    public const SCAN_QUARANTINED = 'quarantined';

    public function isUsable(): bool
    {
        return $this->scan_state === self::SCAN_CLEAN;
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @return HasOne<ApprovalRequest, $this>
     */
    public function approvalRequest(): HasOne
    {
        return $this->hasOne(ApprovalRequest::class);
    }
}
