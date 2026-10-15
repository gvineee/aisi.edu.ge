<?php

namespace App\Domain\Finance\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A payment reminder sent to an invoice's financial guardians.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $invoice_id
 * @property int|null $sent_by
 * @property int $recipients
 * @property Carbon $sent_at
 */
#[Fillable(['invoice_id', 'sent_by', 'recipients', 'sent_at'])]
class InvoiceReminder extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'recipients' => 'integer'];
    }
}
