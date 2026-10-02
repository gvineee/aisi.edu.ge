<?php

namespace App\Domain\Finance\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Money actually received (confirmed by a verified webhook), with whatever is not yet allocated kept as account credit.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $billing_account_id
 * @property int|null $payment_intent_id
 * @property string $provider
 * @property string $provider_payment_id
 * @property int $amount_minor
 * @property string $currency
 * @property int $unallocated_minor
 * @property int $refunded_minor
 * @property Carbon $received_at
 */
#[Fillable(['billing_account_id', 'payment_intent_id', 'provider', 'provider_payment_id', 'amount_minor', 'currency', 'unallocated_minor', 'refunded_minor', 'received_at'])]
class Payment extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'unallocated_minor' => 'integer',
            'refunded_minor' => 'integer',
            'received_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<BillingAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(BillingAccount::class, 'billing_account_id');
    }

    /**
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }
}
