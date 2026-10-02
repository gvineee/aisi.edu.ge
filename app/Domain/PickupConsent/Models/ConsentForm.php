<?php

namespace App\Domain\PickupConsent\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

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
 * @property string|null $body_checksum
 * @property bool $requires_signature
 * @property Carbon|null $responses_close_at
 * @property int $created_by
 */
#[Fillable(['title', 'body', 'body_checksum', 'requires_signature', 'responses_close_at', 'created_by'])]
class ConsentForm extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'requires_signature' => 'boolean',
            'responses_close_at' => 'datetime',
        ];
    }

    public static function checksumFor(string $title, string $body): string
    {
        return hash('sha256', $title."\n".$body);
    }

    public function isClosed(): bool
    {
        return $this->responses_close_at !== null && $this->responses_close_at->isPast();
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
