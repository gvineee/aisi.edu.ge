<?php

namespace App\Domain\Finance\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A started checkout. The provider_reference is the trusted merchant-side key a webhook is matched by — the webhook's own tenant claims are never used.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $invoice_id
 * @property string $provider
 * @property string $provider_reference
 * @property int $amount_minor
 * @property string $currency
 * @property string $status
 * @property int $created_by
 */
#[Fillable(['invoice_id', 'provider', 'provider_reference', 'amount_minor', 'currency', 'status', 'created_by'])]
class PaymentIntent extends Model
{
    use BelongsToTenant;

    public const STATUS_CREATED = 'created';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
