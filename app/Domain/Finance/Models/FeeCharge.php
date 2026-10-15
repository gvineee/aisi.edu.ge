<?php

namespace App\Domain\Finance\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marks one contract installment as billed. Unique per (contract,
 * installment), so invoice generation can never bill twice.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $student_fee_contract_id
 * @property int $fee_plan_installment_id
 * @property int|null $invoice_id null when fully waived by a 100% discount
 */
#[Fillable(['student_fee_contract_id', 'fee_plan_installment_id', 'invoice_id'])]
class FeeCharge extends Model
{
    use BelongsToTenant;

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
