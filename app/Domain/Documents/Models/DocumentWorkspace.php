<?php

namespace App\Domain\Documents\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $title
 * @property string $classification
 */
#[Fillable(['title', 'classification', 'created_by'])]
class DocumentWorkspace extends Model
{
    use BelongsToTenant;

    public const CLASSIFICATION_CURRICULUM = 'curriculum';

    public const CLASSIFICATION_PROJECTS = 'projects';

    public const CLASSIFICATION_POLICIES = 'policies';

    public const CLASSIFICATION_MINUTES = 'minutes';

    public const CLASSIFICATION_ORDERS = 'orders';

    public const CLASSIFICATION_TEMPLATES = 'templates';

    public const CLASSIFICATION_STAFF_PERSONAL = 'staff_personal';

    public const CLASSIFICATION_RESTRICTED = 'restricted';

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
