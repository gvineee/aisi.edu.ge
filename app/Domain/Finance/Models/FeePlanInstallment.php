<?php

namespace App\Domain\Finance\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One installment of a fee plan: invoiced automatically on issue_on, due
 * on due_on.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $fee_plan_id
 * @property string $label
 * @property int $amount_minor
 * @property Carbon $issue_on
 * @property Carbon $due_on
 */
#[Fillable(['fee_plan_id', 'label', 'amount_minor', 'issue_on', 'due_on'])]
class FeePlanInstallment extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'issue_on' => 'date', 'due_on' => 'date'];
    }

    /**
     * @return BelongsTo<FeePlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(FeePlan::class, 'fee_plan_id');
    }
}
