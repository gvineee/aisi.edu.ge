<?php

namespace App\Domain\PickupConsent\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A school-wide consent request (e.g. a field trip or a photo-use
 * permission), published by an admin/director (CLAUDE-PLATFORM-MODULES.md
 * §7). This module keeps the form itself immutable once published — there is
 * no edit action; a changed policy is a new form, matching the "text change
 * creates a new request" rule in the module brief.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $title
 * @property string $body
 * @property bool $requires_signature
 * @property int $created_by
 */
#[Fillable(['title', 'body', 'requires_signature', 'created_by'])]
class ConsentForm extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'requires_signature' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<ConsentResponse, $this>
     */
    public function responses(): HasMany
    {
        return $this->hasMany(ConsentResponse::class);
    }
}
