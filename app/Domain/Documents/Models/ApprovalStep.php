<?php

namespace App\Domain\Documents\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One reviewer's place in an approval request, snapshotted from the
 * workspace's review chain when the version is submitted (a later change
 * to the chain does not alter running requests).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $approval_request_id
 * @property int $position
 * @property int $reviewer_id
 * @property string $state
 * @property CarbonImmutable|null $acted_at
 * @property CarbonImmutable|null $reminded_at
 * @property-read ApprovalRequest $approvalRequest
 * @property-read User $reviewer
 */
#[Fillable(['approval_request_id', 'position', 'reviewer_id', 'state', 'acted_at', 'reminded_at'])]
class ApprovalStep extends Model
{
    use BelongsToTenant;

    public const STATE_PENDING = 'pending';

    public const STATE_APPROVED = 'approved';

    public const STATE_RETURNED = 'returned';

    /** The author was in the chain; their own step is never theirs to approve. */
    public const STATE_SKIPPED = 'skipped';

    /** Another step returned the version, or a newer version superseded it. */
    public const STATE_CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'acted_at' => 'datetime',
            'reminded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ApprovalRequest, $this>
     */
    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
