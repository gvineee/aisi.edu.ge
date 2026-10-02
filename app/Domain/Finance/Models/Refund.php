<?php

namespace App\Domain\Finance\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A refund of unallocated credit back to the payer.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $payment_id
 * @property int $amount_minor
 * @property string $currency
 * @property string $reason
 * @property string|null $provider_refund_id
 * @property string $status
 * @property int $requested_by
 */
#[Fillable(['payment_id', 'amount_minor', 'currency', 'reason', 'provider_refund_id', 'status', 'requested_by'])]
class Refund extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
