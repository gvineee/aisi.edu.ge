<?php

namespace App\Domain\Timetable\Models;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A recurring weekly slot. `starts_at`/`ends_at` are wall-clock time in the
 * school's own timezone (Asia/Tbilisi) — not UTC instants, same reasoning
 * as other calendar-shaped fields (docs/02 §5.3).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $academic_year_id
 * @property int $school_class_id
 * @property int $subject_id
 * @property int $teacher_id
 * @property int|null $room_id
 * @property int $day_of_week
 * @property string $starts_at
 * @property string $ends_at
 * @property string|null $online_url
 * @property string $status
 */
#[Fillable([
    'academic_year_id', 'school_class_id', 'subject_id', 'teacher_id', 'room_id',
    'day_of_week', 'starts_at', 'ends_at', 'online_url', 'status', 'created_by',
])]
class Lesson extends Model
{
    use BelongsToTenant;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    /**
     * @return BelongsTo<AcademicYear, $this>
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * @return BelongsTo<SchoolClass, $this>
     */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }

    /**
     * @return BelongsTo<Subject, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * @return HasMany<LessonException, $this>
     */
    public function exceptions(): HasMany
    {
        return $this->hasMany(LessonException::class);
    }
}
