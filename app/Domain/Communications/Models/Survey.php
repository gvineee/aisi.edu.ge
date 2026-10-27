<?php

namespace App\Domain\Communications\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A survey for guardians, staff, pupils or everyone. Questions are fixed
 * once it opens; it takes answers while open and until `closes_on`.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $title
 * @property string|null $description
 * @property string $audience
 * @property bool $is_anonymous
 * @property string $status
 * @property Carbon|null $closes_on
 * @property Carbon|null $opened_at
 * @property Carbon|null $closed_at
 * @property int|null $created_by
 */
#[Fillable(['title', 'description', 'audience', 'is_anonymous', 'status', 'closes_on', 'opened_at', 'closed_at', 'created_by'])]
class Survey extends Model
{
    use BelongsToTenant;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    /**
     * Which membership roles each audience reaches.
     *
     * @var array<string, list<string>>
     */
    public const AUDIENCES = [
        'guardians' => [TenantMembership::ROLE_GUARDIAN],
        'students' => [TenantMembership::ROLE_STUDENT],
        'staff' => [
            TenantMembership::ROLE_TEACHER,
            TenantMembership::ROLE_ACADEMIC_MANAGER,
            TenantMembership::ROLE_ACCOUNTANT,
            TenantMembership::ROLE_EDITOR,
            TenantMembership::ROLE_LIBRARIAN,
            TenantMembership::ROLE_DIRECTOR,
            TenantMembership::ROLE_ADMIN,
        ],
        'everyone' => [
            TenantMembership::ROLE_GUARDIAN,
            TenantMembership::ROLE_STUDENT,
            TenantMembership::ROLE_TEACHER,
            TenantMembership::ROLE_ACADEMIC_MANAGER,
            TenantMembership::ROLE_ACCOUNTANT,
            TenantMembership::ROLE_EDITOR,
            TenantMembership::ROLE_LIBRARIAN,
            TenantMembership::ROLE_DIRECTOR,
            TenantMembership::ROLE_ADMIN,
        ],
    ];

    protected function casts(): array
    {
        return ['is_anonymous' => 'boolean', 'closes_on' => 'date', 'opened_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    /**
     * @return HasMany<SurveyQuestion, $this>
     */
    public function questions(): HasMany
    {
        return $this->hasMany(SurveyQuestion::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<SurveyResponse, $this>
     */
    public function responses(): HasMany
    {
        return $this->hasMany(SurveyResponse::class);
    }

    public function isAcceptingAnswers(Carbon $today): bool
    {
        return $this->status === self::STATUS_OPEN
            && ($this->closes_on === null || $this->closes_on->toDateString() >= $today->toDateString());
    }
}
