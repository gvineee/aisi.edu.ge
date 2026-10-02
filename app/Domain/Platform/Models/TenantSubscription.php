<?php

namespace App\Domain\Platform\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A tenant's plan. SaaS subscription — never mixed with student tuition.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $subscription_plan_id
 * @property string $status
 * @property Carbon $starts_on
 * @property Carbon|null $ends_on
 */
#[Fillable(['tenant_id', 'subscription_plan_id', 'status', 'starts_on', 'ends_on'])]
class TenantSubscription extends Model
{
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<SubscriptionPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }
}
