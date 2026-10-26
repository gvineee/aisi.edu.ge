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
 * @property int|null $grade_level
 * @property string|null $short_name
 * @property int|null $homeroom_teacher_id
 * @property int|null $homeroom_staff_member_id the homeroom teacher in the staff directory (may have no login yet)
 * @property int|null $home_room_id
 */
#[Fillable(['academic_year_id', 'name', 'grade_level', 'short_name', 'homeroom_teacher_id', 'homeroom_staff_member_id', 'home_room_id'])]
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
     * @return BelongsTo<StaffMember, $this>
     */
    public function homeroomStaffMember(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class, 'homeroom_staff_member_id');
    }

    /**
     * Additional staff on the class, e.g. the second homeroom teacher.
     *
     * @return HasMany<ClassStaffRole, $this>
     */
    public function staffRoles(): HasMany
    {
        return $this->hasMany(ClassStaffRole::class);
    }

    /**
     * @return HasMany<StudentGroup, $this>
     */
    public function groups(): HasMany
    {
        return $this->hasMany(StudentGroup::class);
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
