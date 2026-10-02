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

    public const FEATURE_CLUBS = 'clubs';

    public const FEATURE_TRANSPORT = 'transport';

    public const FEATURE_MEALS = 'meals';

    /**
     * Features a tenant can switch on independently. A missing row means
     * "off" — new modules start disabled until the school opts in.
     *
     * @var array<int, string>
     */
    public const ALL = [
        self::FEATURE_WEB,
        self::FEATURE_SCHOOL_PORTAL,
        self::FEATURE_FINANCE,
        self::FEATURE_NETWORK,
        self::FEATURE_CLUBS,
        self::FEATURE_TRANSPORT,
        self::FEATURE_MEALS,
    ];

    public static function isEnabled(int $tenantId, string $feature): bool
    {
        return static::query()
            ->where('tenant_id', $tenantId)
            ->where('feature', $feature)
            ->where('is_enabled', true)
            ->exists();
    }

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
