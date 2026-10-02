<?php

namespace App\Domain\Finance\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An issued invoice. Amounts are integer minor units. Never deleted; voiding keeps the record with a reason.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $billing_account_id
 * @property int $fiscal_year
 * @property string $number
 * @property string $status
 * @property Carbon $issued_on
 * @property Carbon $due_on
 * @property string $currency
 * @property int $total_minor
 * @property int $paid_minor
 * @property string|null $void_reason
 * @property Carbon|null $voided_at
 * @property int $created_by
 */
#[Fillable(['billing_account_id', 'fiscal_year', 'number', 'status', 'issued_on', 'due_on', 'currency', 'total_minor', 'paid_minor', 'void_reason', 'voided_at', 'created_by'])]
class Invoice extends Model
{
    use BelongsToTenant;

    public const STATUS_ISSUED = 'issued';

    public const STATUS_PARTIALLY_PAID = 'partially_paid';

    public const STATUS_PAID = 'paid';

    public const STATUS_VOID = 'void';

    public function outstandingMinor(): int
    {
        return max(0, $this->total_minor - $this->paid_minor);
    }

    protected function casts(): array
    {
        return [
            'fiscal_year' => 'integer',
            'issued_on' => 'date',
            'due_on' => 'date',
            'total_minor' => 'integer',
            'paid_minor' => 'integer',
            'voided_at' => 'datetime',
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
     * @return HasMany<InvoiceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /**
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }
}
