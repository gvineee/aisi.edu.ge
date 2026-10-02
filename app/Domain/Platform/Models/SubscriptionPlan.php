<?php

namespace App\Domain\Platform\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A SaaS plan (configuration only). Price stays empty until real
 * commercial terms exist — nothing is invented.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property int|null $price_minor
 * @property string $currency
 * @property string|null $billing_period
 * @property bool $is_active
 */
#[Fillable(['code', 'name', 'description', 'price_minor', 'currency', 'billing_period', 'is_active'])]
class SubscriptionPlan extends Model
{
    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<PlanFeature, $this>
     */
    public function features(): HasMany
    {
        return $this->hasMany(PlanFeature::class);
    }
}
