<?php

namespace App\Domain\Finance\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * The school's own payment instructions shown to families (recipient,
 * bank accounts). Empty until the school fills it in — never seeded.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string|null $recipient_name
 * @property string|null $recipient_code
 * @property array<int, array{bank: string, iban: string}>|null $bank_accounts
 * @property string|null $instructions
 */
#[Fillable(['recipient_name', 'recipient_code', 'bank_accounts', 'instructions'])]
class FinanceSetting extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['bank_accounts' => 'array'];
    }

    public static function forTenant(int $tenantId): ?self
    {
        return self::query()->where('tenant_id', $tenantId)->first();
    }

    /**
     * @return array{recipientName: string|null, recipientCode: string|null, bankAccounts: array<int, array{bank: string, iban: string}>, instructions: string|null}
     */
    public function toPublicArray(): array
    {
        return [
            'recipientName' => $this->recipient_name,
            'recipientCode' => $this->recipient_code,
            'bankAccounts' => array_values($this->bank_accounts ?? []),
            'instructions' => $this->instructions,
        ];
    }
}
