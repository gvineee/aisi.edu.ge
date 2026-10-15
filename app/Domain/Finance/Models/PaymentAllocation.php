<?php

namespace App\Domain\Finance\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Part of a payment applied to an invoice. Reversing a manual payment
 * stamps reversed_at instead of deleting the row (audit trail).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $payment_id
 * @property int $invoice_id
 * @property int $amount_minor
 * @property int|null $allocated_by
 * @property Carbon|null $reversed_at
 */
#[Fillable(['payment_id', 'invoice_id', 'amount_minor', 'allocated_by', 'reversed_at'])]
class PaymentAllocation extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'reversed_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<PaymentAllocation>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereNull('reversed_at');
    }
}
