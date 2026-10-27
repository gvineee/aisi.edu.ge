<?php

namespace App\Domain\Learning\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One topic of a curriculum plan, with the lessons it should take and,
 * optionally, the weeks it is planned for.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $curriculum_plan_id
 * @property int $position
 * @property string $title
 * @property int $planned_hours
 * @property Carbon|null $planned_from
 * @property Carbon|null $planned_to
 */
#[Fillable(['curriculum_plan_id', 'position', 'title', 'planned_hours', 'planned_from', 'planned_to'])]
class CurriculumTopic extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['planned_from' => 'date', 'planned_to' => 'date'];
    }

    /**
     * @return BelongsTo<CurriculumPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(CurriculumPlan::class, 'curriculum_plan_id');
    }
}
