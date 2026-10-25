<?php

namespace App\Domain\Academics\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A teaching group inside one class (a split English lesson, ჯგუფი 1/2).
 * Members are added explicitly; nobody joins a group automatically.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $school_class_id
 * @property string $name
 */
#[Fillable(['school_class_id', 'name'])]
class StudentGroup extends Model
{
    use BelongsToTenant;

    /**
     * @return BelongsTo<SchoolClass, $this>
     */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }

    /**
     * @return BelongsToMany<Student, $this>
     */
    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'student_group_members')
            ->withPivot('tenant_id')
            ->withTimestamps();
    }
}
