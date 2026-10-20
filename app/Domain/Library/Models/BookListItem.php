<?php

namespace App\Domain\Library\Models;

use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One book on a class's book list (docs/02 §5.5): edition, author,
 * optional ISBN, required/optional, and how families get it (purchase,
 * library loan, licensed digital access). May point at a catalog title.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $school_class_id
 * @property int|null $library_resource_id
 * @property string $title
 * @property string|null $author
 * @property string|null $edition
 * @property string|null $isbn
 * @property string|null $subject
 * @property string $requirement
 * @property string $acquisition
 * @property string|null $note
 * @property int $position
 * @property int $created_by
 * @property-read LibraryResource|null $resource
 * @property-read SchoolClass $schoolClass
 */
#[Fillable(['school_class_id', 'library_resource_id', 'title', 'author', 'edition', 'isbn', 'subject', 'requirement', 'acquisition', 'note', 'position', 'created_by'])]
class BookListItem extends Model
{
    use BelongsToTenant;

    /** @var array<int, string> */
    public const REQUIREMENTS = ['required', 'optional'];

    /** @var array<int, string> */
    public const ACQUISITIONS = ['purchase', 'loan', 'digital'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<SchoolClass, $this>
     */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }

    /**
     * @return BelongsTo<LibraryResource, $this>
     */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(LibraryResource::class, 'library_resource_id');
    }
}
