<?php

namespace App\Domain\Tenancy\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property string $role
 * @property bool $is_active
 */
#[Fillable(['tenant_id', 'user_id', 'role', 'is_active'])]
class TenantMembership extends Model
{
    public const ROLE_STUDENT = 'student';

    public const ROLE_GUARDIAN = 'guardian';

    public const ROLE_TEACHER = 'teacher';

    public const ROLE_ACADEMIC_MANAGER = 'academic_manager';

    public const ROLE_ACCOUNTANT = 'accountant';

    public const ROLE_EDITOR = 'editor';

    public const ROLE_ADMIN = 'admin';

    public const ROLE_DIRECTOR = 'director';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The one place role-gated controllers should ask "does this user hold
     * this role, right now, in this tenant?" — never trust a client-sent
     * role claim instead of this query.
     */
    public static function userHasActiveRole(int $tenantId, int $userId, string $role): bool
    {
        return static::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('role', $role)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * @param  array<int, string>  $roles
     */
    public static function userHasAnyActiveRole(int $tenantId, int $userId, array $roles): bool
    {
        return static::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->whereIn('role', $roles)
            ->where('is_active', true)
            ->exists();
    }
}
