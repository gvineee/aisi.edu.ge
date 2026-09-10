<?php

namespace App\Domain\Tenancy\Concerns;

use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Auto-scopes queries to the tenant resolved for this request.
 *
 * This is a convenience, not the isolation guarantee — per CLAUDE.md, every
 * read/write path (policies, route binding, jobs, exports, raw queries) must
 * independently check tenant_id rather than relying on this scope alone. In
 * particular this trait does nothing outside an HTTP request with a resolved
 * tenant (e.g. console commands, queue jobs) — those call sites must filter
 * by tenant_id explicitly.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            $tenant = app(CurrentTenant::class)->get();

            if ($tenant !== null) {
                $builder->where($builder->getModel()->getTable().'.tenant_id', $tenant->id);
            }
        });

        static::creating(function ($model) {
            if (empty($model->tenant_id)) {
                $tenant = app(CurrentTenant::class)->get();

                if ($tenant !== null) {
                    $model->tenant_id = $tenant->id;
                }
            }
        });
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
