<?php

namespace App\Domain\Assessment\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only log of every report card status transition.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $report_card_id
 * @property int $revision
 * @property string $from_status
 * @property string $to_status
 * @property string|null $note
 * @property int $actor_id
 * @property Carbon|null $created_at
 */
#[Fillable(['report_card_id', 'revision', 'from_status', 'to_status', 'note', 'actor_id'])]
class ReportCardApproval extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'revision' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
