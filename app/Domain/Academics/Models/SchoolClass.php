<?php

namespace App\Domain\Academics\Models;

use App\Domain\Library\Models\BookListItem;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $academic_year_id
 * @property string $name
 * @property int|null $homeroom_teacher_id
 */
#[Fillable(['academic_year_id', 'name', 'homeroom_teacher_id'])]
class SchoolClass extends Model
{
    use BelongsToTenant;

    /**
     * @return BelongsTo<AcademicYear, $this>
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * The class's homeroom (დამრიგებელი) teacher, who reviews its report
     * cards before the director.
     *
     * @return BelongsTo<User, $this>
     */
    public function homeroomTeacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'homeroom_teacher_id');
    }

    /**
     * @return HasMany<Student, $this>
     */
    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    /**
     * @return HasMany<BookListItem, $this>
     */
    public function bookListItems(): HasMany
    {
        return $this->hasMany(BookListItem::class);
    }

    /**
     * @return HasMany<TeacherAssignment, $this>
     */
    public function teacherAssignments(): HasMany
    {
        return $this->hasMany(TeacherAssignment::class);
    }
}
