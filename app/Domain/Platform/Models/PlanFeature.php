<?php

namespace App\Domain\Platform\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A feature entitlement included in a plan.
 *
 * @property int $id
 * @property int $subscription_plan_id
 * @property string $feature
 */
#[Fillable(['subscription_plan_id', 'feature'])]
class PlanFeature extends Model
{
    public $timestamps = false;
}
