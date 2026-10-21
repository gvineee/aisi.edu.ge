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
 * "Please read this" for one published version of a document, to the
 * staff in the chosen roles who can see it.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $document_id
 * @property int $document_version_id
 * @property array<int, string> $audience_roles
 * @property Carbon|null $due_on
 * @property string|null $note
 * @property string $status
 * @property int $requested_by
 * @property Carbon|null $created_at
 * @property-read Document $document
 * @property-read DocumentVersion $version
 * @property-read User $requester
 */
#[Fillable(['document_id', 'document_version_id', 'audience_roles', 'due_on', 'note', 'status', 'requested_by'])]
class DocumentAcknowledgementRequest extends Model
{
    use BelongsToTenant;

    public const STATUS_OPEN = 'open';

    /** A newer version was published; a new request replaced this one. */
    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUS_CLOSED = 'closed';

    protected function casts(): array
    {
        return [
            'audience_roles' => 'array',
            'due_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return BelongsTo<DocumentVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'document_version_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return HasMany<DocumentAcknowledgement, $this>
     */
    public function acknowledgements(): HasMany
    {
        return $this->hasMany(DocumentAcknowledgement::class);
    }
}
