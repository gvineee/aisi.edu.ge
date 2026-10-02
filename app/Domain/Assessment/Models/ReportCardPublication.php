<?php

namespace App\Domain\Assessment\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Frozen, published revision of a report card — the document families see
 * and the one a verification code proves. Never updated after creation.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $report_card_id
 * @property int $revision
 * @property string $verification_code
 * @property array<string, mixed> $snapshot
 * @property int $published_by
 * @property Carbon $published_at
 */
#[Fillable(['report_card_id', 'revision', 'verification_code', 'snapshot', 'published_by', 'published_at'])]
class ReportCardPublication extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'snapshot' => 'array',
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ReportCard, $this>
     */
    public function reportCard(): BelongsTo
    {
        return $this->belongsTo(ReportCard::class);
    }
}
