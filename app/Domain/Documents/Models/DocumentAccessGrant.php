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
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $resource_type
 * @property int $resource_id
 * @property int $user_id
 * @property string $permission
 */
#[Fillable(['resource_type', 'resource_id', 'user_id', 'permission'])]
class DocumentAccessGrant extends Model
{
    use BelongsToTenant;

    public const RESOURCE_WORKSPACE = 'workspace';

    public const RESOURCE_DOCUMENT = 'document';

    public const PERMISSION_CONTRIBUTOR = 'contributor';

    public const PERMISSION_APPROVER = 'approver';

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
