<?php

namespace App\Domain\Tenancy\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $feature
 * @property bool $is_enabled
 */
#[Fillable(['tenant_id', 'feature', 'is_enabled'])]
class FeatureEntitlement extends Model
{
    public const FEATURE_WEB = 'web';

    public const FEATURE_SCHOOL_PORTAL = 'school_portal';

    public const FEATURE_FINANCE = 'finance';

    public const FEATURE_NETWORK = 'network';

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
