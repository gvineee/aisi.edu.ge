<?php

namespace App\Domain\Finance\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A correction lowering what an invoice asks for. Never edited or deleted;
 * a wrong credit note is fixed by issuing a new invoice.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $invoice_id
 * @property int $billing_account_id
 * @property int $fiscal_year
 * @property string $number
 * @property Carbon $issued_on
 * @property string $currency
 * @property int $amount_minor
 * @property int $released_minor
 * @property string $reason
 * @property string $idempotency_key
 * @property int $created_by
 * @property Carbon|null $created_at
 */
#[Fillable(['invoice_id', 'billing_account_id', 'fiscal_year', 'number', 'issued_on', 'currency', 'amount_minor', 'released_minor', 'reason', 'idempotency_key', 'created_by'])]
class CreditNote extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'fiscal_year' => 'integer',
            'issued_on' => 'date',
            'amount_minor' => 'integer',
            'released_minor' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<BillingAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(BillingAccount::class, 'billing_account_id');
    }
}
