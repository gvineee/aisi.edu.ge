<?php

namespace App\Domain\Academics\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Links a guardian (User) to a Student with separately revocable
 * permissions. `is_active = false` must hide the student from that
 * guardian immediately — see docs/02 critical check #2.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property int $student_id
 * @property bool $can_view_academic
 * @property bool $can_view_financial
 * @property bool $can_pickup
 * @property bool $can_receive_notifications
 * @property bool $is_active
 */
#[Fillable([
    'user_id', 'student_id', 'can_view_academic', 'can_view_financial',
    'can_pickup', 'can_receive_notifications', 'is_active',
])]
class GuardianLink extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'can_view_academic' => 'boolean',
            'can_view_financial' => 'boolean',
            'can_pickup' => 'boolean',
            'can_receive_notifications' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function guardian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
