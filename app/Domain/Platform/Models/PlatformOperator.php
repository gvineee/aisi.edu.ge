<?php

namespace App\Domain\Platform\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A platform (SaaS) operator. Entirely separate from tenant roles: being an
 * operator grants no access to any school's data by itself.
 *
 * @property int $id
 * @property int $user_id
 * @property bool $is_active
 */
#[Fillable(['user_id', 'is_active'])]
class PlatformOperator extends Model
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
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
