<?php

namespace App\Domain\Assessment\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only homeroom/director comment on a report card revision.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $report_card_id
 * @property int $revision
 * @property string $kind
 * @property string $body
 * @property int $author_id
 * @property Carbon|null $created_at
 */
#[Fillable(['report_card_id', 'revision', 'kind', 'body', 'author_id'])]
class ReportCardComment extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    public const KIND_HOMEROOM = 'homeroom';

    public const KIND_DIRECTOR = 'director';

    protected function casts(): array
    {
        return [
            'revision' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
