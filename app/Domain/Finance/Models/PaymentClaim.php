<?php

namespace App\Domain\Finance\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A guardian's notice that they paid an invoice, awaiting the accountant
 * (see ManagePaymentClaims). Amounts are integer tetri.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $invoice_id
 * @property int $submitted_by
 * @property int $amount_minor
 * @property string $currency
 * @property string $method
 * @property Carbon $paid_on
 * @property string|null $reference
 * @property string|null $note
 * @property string $status
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $review_note
 * @property int|null $payment_id
 * @property-read Invoice|null $invoice
 * @property-read User|null $submitter
 */
#[Fillable(['invoice_id', 'submitted_by', 'amount_minor', 'currency', 'method', 'paid_on', 'reference', 'note', 'status', 'reviewed_by', 'reviewed_at', 'review_note', 'payment_id'])]
class PaymentClaim extends Model
{
    use BelongsToTenant;

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_REJECTED = 'rejected';

    /** What a guardian can report: a transfer, or cash paid at the school. */
    public const METHODS = [Payment::METHOD_BANK_TRANSFER, Payment::METHOD_CASH];

    protected function casts(): array
    {
        return ['paid_on' => 'date', 'reviewed_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }
}
