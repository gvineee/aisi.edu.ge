<?php

namespace App\Domain\Documents\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An additive grant beyond a user's base role — never a restriction. Absence
 * of a grant simply means "no extra access here", not "denied everywhere".
 * A grant targets one user (`user_id`) or every active holder of a tenant
 * role (`role`); DocumentAccess resolves the effective level.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $resource_type
 * @property int $resource_id
 * @property int|null $user_id
 * @property string|null $role
 * @property string $permission
 * @property int|null $granted_by
 */
#[Fillable(['resource_type', 'resource_id', 'user_id', 'role', 'permission', 'granted_by'])]
class DocumentAccessGrant extends Model
{
    use BelongsToTenant;

    public const RESOURCE_WORKSPACE = 'workspace';

    public const RESOURCE_DOCUMENT = 'document';

    public const PERMISSION_CONTRIBUTOR = 'contributor';

    public const PERMISSION_APPROVER = 'approver';

    public const PERMISSION_VIEW = 'view';

    public const PERMISSION_EDIT = 'edit';

    public const PERMISSION_MANAGE = 'manage';

    /**
     * Levels a new grant may carry. The two Stage 1+2 values above are still
     * honoured for existing rows (contributor = edit, approver = manage).
     *
     * @var array<int, string>
     */
    public const LEVELS = [self::PERMISSION_VIEW, self::PERMISSION_EDIT, self::PERMISSION_MANAGE];

    public function level(): int
    {
        return match ($this->permission) {
            self::PERMISSION_VIEW => DocumentAccessLevel::VIEW,
            self::PERMISSION_EDIT, self::PERMISSION_CONTRIBUTOR => DocumentAccessLevel::EDIT,
            self::PERMISSION_MANAGE, self::PERMISSION_APPROVER => DocumentAccessLevel::MANAGE,
            default => DocumentAccessLevel::NONE,
        };
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
