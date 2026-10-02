<?php

namespace App\Domain\Finance\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A line on an invoice.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $invoice_id
 * @property string $description
 * @property int $quantity
 * @property int $unit_minor
 * @property int $amount_minor
 * @property string|null $source_type
 * @property int|null $source_id
 */
#[Fillable(['invoice_id', 'description', 'quantity', 'unit_minor', 'amount_minor', 'source_type', 'source_id'])]
class InvoiceItem extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_minor' => 'integer',
            'amount_minor' => 'integer',
            'source_id' => 'integer',
        ];
    }
}
