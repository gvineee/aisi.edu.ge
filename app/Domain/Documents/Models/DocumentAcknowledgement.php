<?php

namespace App\Domain\Documents\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One recipient's receipt: who, which version, when told, when they
 * confirmed "გავეცანი". Opening or downloading the file is not an
 * acknowledgement (docs/07 §5).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $document_acknowledgement_request_id
 * @property int $document_version_id
 * @property int $user_id
 * @property Carbon|null $notified_at
 * @property Carbon|null $acknowledged_at
 * @property-read DocumentAcknowledgementRequest $request
 * @property-read User $user
 */
#[Fillable(['document_acknowledgement_request_id', 'document_version_id', 'user_id', 'notified_at', 'acknowledged_at'])]
class DocumentAcknowledgement extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'notified_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<DocumentAcknowledgementRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(DocumentAcknowledgementRequest::class, 'document_acknowledgement_request_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
