<?php

namespace App\Domain\Timetable\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $lesson_id
 * @property Carbon $occurs_on
 * @property string $type
 * @property int|null $substitute_teacher_id
 * @property int|null $substitute_room_id
 * @property string|null $note
 */
#[Fillable(['lesson_id', 'occurs_on', 'type', 'substitute_teacher_id', 'substitute_room_id', 'note', 'created_by'])]
class LessonException extends Model
{
    use BelongsToTenant;

    public const TYPE_CANCELLED = 'cancelled';

    public const TYPE_SUBSTITUTION = 'substitution';

    public const TYPE_ROOM_CHANGE = 'room_change';

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

    /**
     * @return BelongsTo<User, $this>
     */
    public function substituteTeacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'substitute_teacher_id');
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function substituteRoom(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'substitute_room_id');
    }
}
