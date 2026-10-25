<?php

namespace App\Domain\Learning\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Timetable\Models\Lesson;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The class-journal entry for one lesson occurrence: what was taught.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $lesson_id
 * @property Carbon $occurs_on
 * @property string|null $topic
 * @property int|null $recorded_by
 */
#[Fillable(['lesson_id', 'occurs_on', 'topic', 'recorded_by'])]
class LessonRecord extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'occurs_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Lesson, $this>
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }
}
