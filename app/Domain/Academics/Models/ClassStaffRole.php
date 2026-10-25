<?php

namespace App\Domain\Academics\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Staff attached to a class beyond the primary homeroom teacher
 * (`school_classes.homeroom_teacher_id`), e.g. EduPage „დამრიგებელი 2“.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $school_class_id
 * @property int $user_id
 * @property string $role
 */
#[Fillable(['school_class_id', 'user_id', 'role'])]
class ClassStaffRole extends Model
{
    use BelongsToTenant;

    public const ROLE_CO_HOMEROOM = 'co_homeroom';

    /**
     * @return BelongsTo<SchoolClass, $this>
     */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
