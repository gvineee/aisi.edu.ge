<?php

namespace App\Domain\Health\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A narrowly-scoped health capability given to one staff member. Health
 * access never follows from a broad role (admin/director included); it is
 * granted explicitly, revocable, and audit-logged.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property string $capability
 * @property int $granted_by
 * @property Carbon|null $revoked_at
 * @property int|null $revoked_by
 */
#[Fillable(['user_id', 'capability', 'granted_by', 'revoked_at', 'revoked_by'])]
class HealthPermissionGrant extends Model
{
    use BelongsToTenant;

    public const VIEW = 'health.view';

    public const MANAGE = 'health.manage';

    public const INCIDENT_CREATE = 'health.incident.create';

    public const AUDIT_VIEW = 'health.audit.view';

    /**
     * @var array<int, string>
     */
    public const CAPABILITIES = [self::VIEW, self::MANAGE, self::INCIDENT_CREATE, self::AUDIT_VIEW];

    protected function casts(): array
    {
        return [
            'revoked_at' => 'datetime',
        ];
    }
}
