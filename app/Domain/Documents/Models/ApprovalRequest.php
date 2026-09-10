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
 * @property int $id
 * @property int $tenant_id
 * @property int $document_version_id
 * @property int $initiator_id
 * @property string $state
 * @property Carbon|null $due_at
 * @property int $lock_version
 */
#[Fillable(['document_version_id', 'initiator_id', 'state', 'due_at', 'lock_version'])]
class ApprovalRequest extends Model
{
    use BelongsToTenant;

    public const STATE_PENDING = 'pending';

    public const STATE_APPROVED = 'approved';

    public const STATE_RETURNED = 'returned';

    public const STATE_SUPERSEDED = 'superseded';

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<DocumentVersion, $this>
     */
    public function documentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiator_id');
    }

    /**
     * @return HasMany<ApprovalDecision, $this>
     */
    public function decisions(): HasMany
    {
        return $this->hasMany(ApprovalDecision::class);
    }
}
