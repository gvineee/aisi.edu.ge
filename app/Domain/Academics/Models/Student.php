<?php

namespace App\Domain\Academics\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A student is not necessarily a portal user in this phase (docs/02: the
 * student login role is separate from having a Student record at all).
 * `user_id` links this record to the User who logs in as this student, when
 * one exists — most Student rows have no linked account.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $school_class_id
 * @property int|null $user_id
 * @property string $first_name
 * @property string $last_name
 * @property string|null $national_id
 * @property bool $is_active
 */
#[Fillable(['school_class_id', 'user_id', 'first_name', 'last_name', 'national_id', 'is_active'])]
class Student extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<GuardianLink, $this>
     */
    public function guardianLinks(): HasMany
    {
        return $this->hasMany(GuardianLink::class);
    }
}
