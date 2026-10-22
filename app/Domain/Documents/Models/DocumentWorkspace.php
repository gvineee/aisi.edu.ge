<?php

namespace App\Domain\Documents\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $title
 * @property string|null $description
 * @property string $classification
 * @property string $visibility
 * @property bool|null $requires_approval
 * @property string $review_mode
 * @property int|null $review_days
 * @property bool $is_confidential
 * @property int|null $created_by
 * @property Carbon|null $archived_at
 */
#[Fillable(['title', 'description', 'classification', 'visibility', 'requires_approval', 'review_mode', 'review_days', 'is_confidential', 'created_by'])]
class DocumentWorkspace extends Model
{
    use BelongsToTenant;

    /** Any manager of the document decides (the original flow). */
    public const REVIEW_SINGLE = 'single';

    /** The chain's reviewers decide one after another. */
    public const REVIEW_SEQUENTIAL = 'sequential';

    /** Every reviewer in the chain must approve, in any order. */
    public const REVIEW_ALL = 'all';

    /** @var array<int, string> */
    public const REVIEW_MODES = [self::REVIEW_SINGLE, self::REVIEW_SEQUENTIAL, self::REVIEW_ALL];

    public const VISIBILITY_ALL_STAFF = 'all_staff';

    public const VISIBILITY_RESTRICTED = 'restricted';

    public const CLASSIFICATION_CURRICULUM = 'curriculum';

    public const CLASSIFICATION_PROJECTS = 'projects';

    public const CLASSIFICATION_POLICIES = 'policies';

    public const CLASSIFICATION_MINUTES = 'minutes';

    public const CLASSIFICATION_ORDERS = 'orders';

    public const CLASSIFICATION_TEMPLATES = 'templates';

    public const CLASSIFICATION_STAFF_PERSONAL = 'staff_personal';

    public const CLASSIFICATION_RESTRICTED = 'restricted';

    public const CLASSIFICATION_CONTRACTS = 'contracts';

    public const CLASSIFICATION_INTERNAL = 'internal';

    /**
     * @var array<int, string>
     */
    public const CLASSIFICATIONS = [
        self::CLASSIFICATION_CURRICULUM,
        self::CLASSIFICATION_PROJECTS,
        self::CLASSIFICATION_POLICIES,
        self::CLASSIFICATION_MINUTES,
        self::CLASSIFICATION_ORDERS,
        self::CLASSIFICATION_TEMPLATES,
        self::CLASSIFICATION_STAFF_PERSONAL,
        self::CLASSIFICATION_RESTRICTED,
        self::CLASSIFICATION_CONTRACTS,
        self::CLASSIFICATION_INTERNAL,
    ];

    protected function casts(): array
    {
        return [
            'requires_approval' => 'boolean',
            'is_confidential' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * A freshly created model has not read the column default back yet;
     * treat a missing value as the original Stage 1+2 behaviour.
     */
    public function requiresApproval(): bool
    {
        return $this->requires_approval ?? true;
    }

    public function usesReviewChain(): bool
    {
        return $this->requiresApproval() && in_array($this->review_mode, [self::REVIEW_SEQUENTIAL, self::REVIEW_ALL], true);
    }

    /**
     * @return HasMany<DocumentWorkspaceReviewer, $this>
     */
    public function reviewers(): HasMany
    {
        return $this->hasMany(DocumentWorkspaceReviewer::class, 'workspace_id')->orderBy('position');
    }

    public function isRestricted(): bool
    {
        return $this->visibility === self::VISIBILITY_RESTRICTED;
    }

    /**
     * Downloads from these are written to the audit log.
     */
    public function isConfidential(): bool
    {
        return (bool) $this->is_confidential || $this->isRestricted()
            || in_array($this->classification, [self::CLASSIFICATION_CONTRACTS, self::CLASSIFICATION_STAFF_PERSONAL, self::CLASSIFICATION_RESTRICTED], true);
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'workspace_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
