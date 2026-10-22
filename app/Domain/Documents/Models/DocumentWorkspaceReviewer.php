<?php

namespace App\Domain\Documents\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One reviewer in a workspace's review chain, in order.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $workspace_id
 * @property int $user_id
 * @property int $position
 * @property-read User $user
 */
#[Fillable(['workspace_id', 'user_id', 'position'])]
class DocumentWorkspaceReviewer extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
