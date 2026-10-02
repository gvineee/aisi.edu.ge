<?php

namespace App\Domain\Assessment\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Append-only history row, one per saved version of an AssessmentResult.
 * Version 1 is the first entry; later versions carry the teacher's reason.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $assessment_result_id
 * @property int $version
 * @property string $raw_score
 * @property string $normalized_percent
 * @property string|null $final_grade
 * @property string|null $reason
 * @property int $changed_by
 * @property Carbon|null $created_at
 */
#[Fillable(['assessment_result_id', 'version', 'raw_score', 'normalized_percent', 'final_grade', 'reason', 'changed_by'])]
class AssessmentResultRevision extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'raw_score' => 'decimal:2',
            'normalized_percent' => 'decimal:2',
            'version' => 'integer',
        ];
    }
}
