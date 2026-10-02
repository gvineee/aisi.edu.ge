<?php

namespace App\Domain\Tenancy\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $domain
 * @property bool $is_primary
 * @property string|null $verification_token
 * @property Carbon|null $verified_at
 */
#[Fillable(['tenant_id', 'domain', 'is_primary', 'verification_token', 'verified_at'])]
class TenantDomain extends Model
{
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * Added through the platform with a DNS token and not yet proven: it
     * must not serve the tenant.
     */
    public function isPending(): bool
    {
        return $this->verification_token !== null && $this->verified_at === null;
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
