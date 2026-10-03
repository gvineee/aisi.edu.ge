<?php

namespace App\Domain\Communications\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Opt-in for out-of-app notification channels. In-app notification is
 * always on; email is sent only when the user switched it on here.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property string $category
 * @property string $channel
 * @property bool $enabled
 */
#[Fillable(['user_id', 'category', 'channel', 'enabled'])]
class NotificationPreference extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    public static function emailEnabled(int $tenantId, int $userId, string $category = 'messages'): bool
    {
        return static::query()->where('tenant_id', $tenantId)->where('user_id', $userId)
            ->where('category', $category)->where('channel', 'email')->where('enabled', true)->exists();
    }
}
