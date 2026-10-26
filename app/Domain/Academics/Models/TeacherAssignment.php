<?php

namespace App\Domain\Academics\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Timetable\Models\Subject;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A teacher only ever sees/edits the classes listed here (docs/02 critical
 * check #3) — never every class in the school.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $user_id the login of the staff member, once they have one
 * @property int|null $staff_member_id
 * @property int $school_class_id
 * @property string|null $subject
 * @property int|null $subject_id
 * @property int|null $student_group_id
 * @property int|null $weekly_hours planned lessons per week (the workload)
 * @property string|null $joint_key shared by the per-class rows of one joint lesson
 * @property-read User|null $teacher
 */
#[Fillable(['user_id', 'staff_member_id', 'school_class_id', 'subject', 'subject_id', 'student_group_id', 'weekly_hours', 'joint_key'])]
class TeacherAssignment extends Model
{
    use BelongsToTenant;

    /**
     * @return BelongsTo<User, $this>
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<StaffMember, $this>
     */
    public function staffMember(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class);
    }

    /**
     * The canonical subject (`subject` is the older free-text label).
     *
     * @return BelongsTo<Subject, $this>
     */
    public function subjectModel(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    /**
     * @return BelongsTo<SchoolClass, $this>
     */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }
}
