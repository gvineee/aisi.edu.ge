<?php

namespace App\Domain\Communications\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A private file on a message; see the migration docblock.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $message_id
 * @property string $storage_path
 * @property string $original_filename
 * @property string $mime
 * @property int $size
 * @property string $checksum
 * @property string $scan_status
 */
class MessageAttachment extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'scanned_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
