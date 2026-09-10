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
}
