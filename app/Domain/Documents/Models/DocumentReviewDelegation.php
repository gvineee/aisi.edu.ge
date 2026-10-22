<?php

namespace App\Domain\Documents\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reviewer's substitute for a limited time (docs/07 §4: "substitute
 * approver დროით შეზღუდულად და audit-ით"). The delegate decides steps
 * assigned to the delegator; decisions record both. Revoking keeps the row.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $delegator_id
 * @property int $delegate_id
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property string $reason
 * @property int $created_by
 * @property CarbonImmutable|null $revoked_at
 * @property-read User $delegator
 * @property-read User $delegate
 */
#[Fillable(['delegator_id', 'delegate_id', 'starts_at', 'ends_at', 'reason', 'created_by', 'revoked_at'])]
class DocumentReviewDelegation extends Model
{
    use BelongsToTenant;

    /** Longest single delegation. */
    public const MAX_DAYS = 90;

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<DocumentReviewDelegation>  $query
     */
    #[Scope]
    protected function activeAt(Builder $query, CarbonInterface $moment): void
    {
        $query->whereNull('revoked_at')->where('starts_at', '<=', $moment)->where('ends_at', '>', $moment);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function delegator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegator_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function delegate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegate_id');
    }
}
