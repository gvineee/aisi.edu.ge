<?php

namespace App\Domain\Library\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One physical copy of a catalog title, identified by the school's
 * inventory number. Never deleted: a lost or discarded copy is marked.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $library_resource_id
 * @property string $inventory_number
 * @property string $status
 * @property string|null $note
 * @property int $created_by
 * @property-read LibraryResource $resource
 */
#[Fillable(['library_resource_id', 'inventory_number', 'status', 'note', 'created_by'])]
class LibraryCopy extends Model
{
    use BelongsToTenant;

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_ON_LOAN = 'on_loan';

    /** Set aside for the first reservation in the queue. */
    public const STATUS_ON_HOLD = 'on_hold';

    public const STATUS_LOST = 'lost';

    public const STATUS_WITHDRAWN = 'withdrawn';

    /**
     * @return BelongsTo<LibraryResource, $this>
     */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(LibraryResource::class, 'library_resource_id');
    }

    /**
     * @return HasMany<LibraryLoan, $this>
     */
    public function loans(): HasMany
    {
        return $this->hasMany(LibraryLoan::class);
    }
}
