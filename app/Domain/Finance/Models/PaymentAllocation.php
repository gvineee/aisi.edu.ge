<?php

namespace App\Domain\Finance\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Part of a payment applied to an invoice.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $payment_id
 * @property int $invoice_id
 * @property int $amount_minor
 * @property int|null $allocated_by
 */
#[Fillable(['payment_id', 'invoice_id', 'amount_minor', 'allocated_by'])]
class PaymentAllocation extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
        ];
    }
}
