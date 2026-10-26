<?php

namespace App\Domain\Academics\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Timetable\Models\Lesson;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A person who works at the school, whether or not they can log in yet.
 * Lessons, workloads, homeroom duties and worked hours belong here; the
 * optional user is only the login. Linking a user (an accepted invitation)
 * also stamps that user onto the staff member's lessons and workloads, so
 * the teacher portal, attendance and substitutions see them.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $user_id
 * @property string $full_name
 * @property string|null $short_name
 * @property bool $is_placeholder
 * @property string $status
 * @property-read User|null $user
 */
#[Fillable(['user_id', 'full_name', 'short_name', 'is_placeholder', 'status'])]
class StaffMember extends Model
{
    use BelongsToTenant;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected function casts(): array
    {
        return ['is_placeholder' => 'boolean'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Lesson, $this>
     */
    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }

    /**
     * @return HasMany<TeacherAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(TeacherAssignment::class);
    }
}
