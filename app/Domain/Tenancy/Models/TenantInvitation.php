<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\Student;
use App\Domain\Tenancy\Actions\AcceptTenantInvitation;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A pending or resolved invitation for someone to gain portal access
 * (docs/02 §5.2: "ვადიანი, ერთჯერადი invitation" — time-limited,
 * single-use). Accepting one atomically creates the invitee's User (if
 * they don't already have one), a TenantMembership for the intended role,
 * and — for a guardian or teacher invite — the matching GuardianLink or
 * TeacherAssignment. See {@see AcceptTenantInvitation}.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $email
 * @property string $role
 * @property int|null $student_id
 * @property int|null $school_class_id
 * @property string|null $subject
 * @property bool $can_view_academic
 * @property bool $can_view_financial
 * @property bool $can_pickup
 * @property bool $can_receive_notifications
 * @property string $token
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property int $invited_by
 */
#[Fillable([
    'email', 'role', 'student_id', 'school_class_id', 'subject',
    'can_view_academic', 'can_view_financial', 'can_pickup', 'can_receive_notifications',
    'token', 'expires_at', 'accepted_at', 'invited_by',
])]
class TenantInvitation extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'can_view_academic' => 'boolean',
            'can_view_financial' => 'boolean',
            'can_pickup' => 'boolean',
            'can_receive_notifications' => 'boolean',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public static function generateToken(): string
    {
        return Str::random(64);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    public function isPending(): bool
    {
        return ! $this->isAccepted() && ! $this->isExpired();
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

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
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
