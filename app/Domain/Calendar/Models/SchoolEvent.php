<?php

namespace App\Domain\Calendar\Models;

use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * One entry of the school calendar, from `starts_on` to `ends_on`
 * inclusive (school-local dates), all day unless `starts_at` is set.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $academic_year_id
 * @property string $title
 * @property string|null $description
 * @property string $category
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property string|null $starts_at
 * @property string|null $ends_at
 * @property string|null $location
 * @property string $audience
 * @property int|null $created_by
 * @property int|null $updated_by
 */
#[Fillable(['academic_year_id', 'title', 'description', 'category', 'starts_on', 'ends_on', 'starts_at', 'ends_at', 'location', 'audience', 'created_by', 'updated_by'])]
class SchoolEvent extends Model
{
    use BelongsToTenant;

    public const CATEGORIES = ['event', 'exam', 'meeting', 'trip', 'other'];

    public const AUDIENCE_EVERYONE = 'everyone';

    public const AUDIENCE_STAFF = 'staff';

    public const AUDIENCE_CLASSES = 'classes';

    public const AUDIENCES = [self::AUDIENCE_EVERYONE, self::AUDIENCE_STAFF, self::AUDIENCE_CLASSES];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date'];
    }

    /**
     * @return BelongsToMany<SchoolClass, $this>
     */
    public function classes(): BelongsToMany
    {
        return $this->belongsToMany(SchoolClass::class, 'school_event_classes')->withPivot('tenant_id');
    }
}
