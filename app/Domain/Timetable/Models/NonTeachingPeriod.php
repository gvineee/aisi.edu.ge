<?php

namespace App\Domain\Timetable\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A holiday or vacation: no timetabled lessons from `starts_on` to
 * `ends_on` inclusive (school-local dates).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $academic_year_id
 * @property string $label
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property int|null $created_by
 */
#[Fillable(['academic_year_id', 'label', 'starts_on', 'ends_on', 'created_by'])]
class NonTeachingPeriod extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date'];
    }
}
