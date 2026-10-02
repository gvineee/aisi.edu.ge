<?php

namespace App\Domain\PickupConsent\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only record of one guardian decision on one consent form for one
 * child, tied to the checksum of the exact text answered. A later change of
 * mind adds a new event; nothing here is ever updated.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $consent_form_id
 * @property int $student_id
 * @property int $guardian_id
 * @property int|null $guardian_link_id
 * @property string $decision
 * @property string $form_checksum
 * @property Carbon|null $created_at
 */
#[Fillable(['consent_form_id', 'student_id', 'guardian_id', 'guardian_link_id', 'decision', 'form_checksum'])]
class ConsentResponseEvent extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    public const GRANTED = 'granted';

    public const DECLINED = 'declined';

    public const REVOKED = 'revoked';

    /**
     * @return BelongsTo<User, $this>
     */
    public function guardian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'guardian_id');
    }
}
