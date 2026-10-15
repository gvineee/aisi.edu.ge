<?php

namespace App\Domain\Finance\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Money actually received — confirmed by a verified provider webhook, or
 * recorded by the accountant from a cash desk / bank statement / POS slip
 * (provider "manual"). Whatever is not yet allocated is account credit.
 *
 * Invariant: amount = active allocations + unallocated + refunded + reversed.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $billing_account_id
 * @property int|null $payment_intent_id
 * @property string $provider
 * @property string $provider_payment_id
 * @property string|null $method
 * @property string|null $reference
 * @property string|null $note
 * @property int|null $recorded_by
 * @property int $amount_minor
 * @property string $currency
 * @property int $unallocated_minor
 * @property int $refunded_minor
 * @property int $reversed_minor
 * @property Carbon|null $reversed_at
 * @property string|null $reversal_reason
 * @property Carbon $received_at
 */
#[Fillable([
    'billing_account_id', 'payment_intent_id', 'provider', 'provider_payment_id', 'method', 'reference', 'note', 'recorded_by',
    'amount_minor', 'currency', 'unallocated_minor', 'refunded_minor', 'reversed_minor', 'reversed_at', 'reversal_reason', 'received_at',
])]
class Payment extends Model
{
    use BelongsToTenant;

    /** Provider name for payments the accountant records by hand. */
    public const PROVIDER_MANUAL = 'manual';

    public const METHOD_CASH = 'cash';

    public const METHOD_BANK_TRANSFER = 'bank_transfer';

    public const METHOD_POS = 'pos';

    public const METHOD_OTHER = 'other';

    /**
     * @var array<int, string>
     */
    public const METHODS = [self::METHOD_CASH, self::METHOD_BANK_TRANSFER, self::METHOD_POS, self::METHOD_OTHER];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'unallocated_minor' => 'integer',
            'refunded_minor' => 'integer',
            'reversed_minor' => 'integer',
            'reversed_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public function isManual(): bool
    {
        return $this->provider === self::PROVIDER_MANUAL;
    }

    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }

    /** Printable receipt number, stable for the payment's lifetime. */
    public function receiptNumber(): string
    {
        return sprintf('Q-%06d', $this->id);
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
