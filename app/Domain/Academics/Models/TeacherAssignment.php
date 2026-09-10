<?php

namespace App\Domain\Academics\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
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
 * @property int $user_id
 * @property int $school_class_id
 * @property string|null $subject
 */
#[Fillable(['user_id', 'school_class_id', 'subject'])]
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
     * @return BelongsTo<SchoolClass, $this>
     */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }
}
