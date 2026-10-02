<?php

namespace App\Domain\Platform\Models;

use App\Domain\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A platform operator's request for temporary, read-only support access to
 * one tenant. Only the tenant's own admin/director can approve it; it
 * expires by itself, can be revoked at any time, and every step is audited.
 * There is no impersonation.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $operator_id
 * @property string $reason
 * @property int $requested_minutes
 * @property string $status
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 */
#[Fillable(['tenant_id', 'operator_id', 'reason', 'requested_minutes', 'status', 'decided_by', 'decided_at', 'expires_at'])]
class SupportAccessRequest extends Model
{
    public function isActive(): bool
    {
        return $this->status === 'approved' && $this->expires_at !== null && $this->expires_at->isFuture();
    }

    protected function casts(): array
    {
        return [
            'requested_minutes' => 'integer',
            'decided_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }
}
