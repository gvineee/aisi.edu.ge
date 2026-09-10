<?php

namespace App\Domain\Documents\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $approval_request_id
 * @property int $reviewer_id
 * @property string $decision
 * @property string|null $comment
 * @property Carbon $acted_at
 */
#[Fillable(['approval_request_id', 'reviewer_id', 'decision', 'comment', 'acted_at'])]
class ApprovalDecision extends Model
{
    use BelongsToTenant;

    public const DECISION_APPROVED = 'approved';

    public const DECISION_RETURNED = 'returned';

    protected function casts(): array
    {
        return [
            'acted_at' => 'datetime',
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
