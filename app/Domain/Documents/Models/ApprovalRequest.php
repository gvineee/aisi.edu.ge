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
     * Supersedes the pending request for a version that a newer version is
     * replacing, and cancels its still-open chain steps.
     */
    public static function supersedePendingFor(int $tenantId, int $versionId): void
    {
        $ids = self::query()->where('tenant_id', $tenantId)->where('document_version_id', $versionId)
            ->where('state', self::STATE_PENDING)->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        ApprovalStep::query()->where('tenant_id', $tenantId)->whereIn('approval_request_id', $ids)
            ->where('state', ApprovalStep::STATE_PENDING)->update(['state' => ApprovalStep::STATE_CANCELLED, 'acted_at' => now()]);
        self::query()->whereKey($ids)->update(['state' => self::STATE_SUPERSEDED]);
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
     * Snapshot of the workspace's review chain; empty in single-reviewer mode.
     *
     * @return HasMany<ApprovalStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalStep::class)->orderBy('position');
    }

    /**
     * @return HasMany<ApprovalDecision, $this>
     */
    public function decisions(): HasMany
    {
        return $this->hasMany(ApprovalDecision::class);
    }
}
