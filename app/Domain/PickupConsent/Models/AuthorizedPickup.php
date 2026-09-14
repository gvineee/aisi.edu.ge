<?php

namespace App\Domain\PickupConsent\Models;

use App\Domain\Academics\Models\Student;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person a guardian has authorized to pick up their child, in addition to
 * the guardians themselves (CLAUDE-PLATFORM-MODULES.md §7). Only the
 * student's own active guardian may add/remove entries — see
 * AddAuthorizedPickup/RemoveAuthorizedPickup, which verify this against
 * `guardian_links` rather than trusting a client-supplied student id.
 *
 * `is_active = false` is a soft revoke: the row stays for the audit trail
 * (who was ever authorized) instead of being deleted.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $student_id
 * @property string $full_name
 * @property string $relationship
 * @property string|null $id_document_number
 * @property bool $is_active
 * @property int $added_by
 */
#[Fillable(['student_id', 'full_name', 'relationship', 'id_document_number', 'is_active', 'added_by'])]
class AuthorizedPickup extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }
}
