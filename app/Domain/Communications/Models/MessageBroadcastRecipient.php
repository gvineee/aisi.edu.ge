<?php

namespace App\Domain\Communications\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Snapshot of one guardian an announcement goes to, and where it landed.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $message_broadcast_id
 * @property int $user_id
 * @property int|null $conversation_id
 * @property string $status
 * @property string|null $failure_reason
 */
class MessageBroadcastRecipient extends Model
{
    use BelongsToTenant;

    public const STATUS_PENDING = 'pending';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_FAILED = 'failed';

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
