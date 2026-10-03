<?php

namespace App\Domain\Communications\Models;

use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A class announcement to its families; see the migration docblock.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $sender_id
 * @property int $school_class_id
 * @property string $sender_role
 * @property string $subject
 * @property string $body
 * @property string $status
 * @property int $recipients_count
 * @property int $delivered_count
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 */
class MessageBroadcast extends Model
{
    use BelongsToTenant;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_PARTIAL = 'partial';

    protected function casts(): array
    {
        return [
            'recipients_count' => 'integer',
            'delivered_count' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /**
     * @return BelongsTo<SchoolClass, $this>
     */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }

    /**
     * @return HasMany<MessageBroadcastRecipient, $this>
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(MessageBroadcastRecipient::class);
    }
}
