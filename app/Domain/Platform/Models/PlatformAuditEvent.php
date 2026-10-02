<?php

namespace App\Domain\Platform\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Append-only log of platform operator actions (tenant creation, domains,
 * features, plans, support access).
 *
 * @property int $id
 * @property int|null $operator_id
 * @property int|null $tenant_id
 * @property string $action
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 */
#[Fillable(['operator_id', 'tenant_id', 'action', 'meta'])]
class PlatformAuditEvent extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }
}
