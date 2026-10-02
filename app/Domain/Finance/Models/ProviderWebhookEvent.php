<?php

namespace App\Domain\Finance\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Every webhook received, valid or not, with what was done about it — the
 * input to reconciliation. The raw payload is not stored, only its hash.
 *
 * Deliberately not BelongsToTenant: the tenant comes only from the matched
 * trusted payment intent, never from the request host, and an unmatched
 * event has no tenant at all. Every read filters tenant_id explicitly.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property string $provider
 * @property string $event_id
 * @property string $event_type
 * @property string $payload_hash
 * @property bool $signature_valid
 * @property string $outcome
 * @property string|null $detail
 * @property Carbon $received_at
 */
#[Fillable(['tenant_id', 'provider', 'event_id', 'event_type', 'payload_hash', 'signature_valid', 'outcome', 'detail', 'received_at'])]
class ProviderWebhookEvent extends Model
{
    protected function casts(): array
    {
        return [
            'signature_valid' => 'boolean',
            'received_at' => 'datetime',
        ];
    }
}
