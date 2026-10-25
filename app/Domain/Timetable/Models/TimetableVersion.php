<?php

namespace App\Domain\Timetable\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A published timetable with its validity window (EduPage „v210,
 * 03.09.2026–01.07.2027“). Lessons of different versions never conflict
 * with each other; legacy lessons have no version.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $academic_year_id
 * @property string $label
 * @property string|null $source_version
 * @property Carbon $valid_from
 * @property Carbon|null $valid_to
 * @property string $status
 */
#[Fillable(['academic_year_id', 'label', 'source_version', 'valid_from', 'valid_to', 'status'])]
class TimetableVersion extends Model
{
    use BelongsToTenant;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
    }

    /**
     * @return HasMany<Lesson, $this>
     */
    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }
}
