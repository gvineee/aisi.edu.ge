<?php

namespace App\Domain\Communications\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $message_id
 * @property int $recipient_id
 * @property Carbon|null $delivered_at
 * @property Carbon|null $read_at
 * @property string $channel
 * @property string $status
 * @property string|null $failure_reason
 * @property int $attempts
 */
#[Fillable(['message_id', 'recipient_id', 'delivered_at', 'read_at', 'channel', 'status', 'failure_reason', 'attempts'])]
class MessageDelivery extends Model
{
    use BelongsToTenant;

    public const CHANNEL_IN_APP = 'in_app';

    public const CHANNEL_EMAIL = 'email';

    protected function casts(): array
    {
        return [
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }
}
