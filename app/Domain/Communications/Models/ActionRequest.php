<?php

namespace App\Domain\Communications\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * "Needs response": a sender asks a conversation participant to reply or
 * acknowledge. A reply (or acknowledgement) by the target closes it.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $conversation_id
 * @property int $message_id
 * @property int $requested_by
 * @property int $target_user_id
 * @property string $type
 * @property Carbon|null $due_at
 * @property string $status
 * @property Carbon|null $completed_at
 */
#[Fillable(['conversation_id', 'message_id', 'requested_by', 'target_user_id', 'type', 'due_at', 'status', 'completed_at'])]
class ActionRequest extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
