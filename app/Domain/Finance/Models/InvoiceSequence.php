<?php

namespace App\Domain\Finance\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-tenant, per-fiscal-year invoice counter, incremented under a row lock.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $fiscal_year
 * @property int $last_number
 */
#[Fillable(['fiscal_year', 'last_number'])]
class InvoiceSequence extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'fiscal_year' => 'integer',
            'last_number' => 'integer',
        ];
    }
}
