<?php

namespace App\Domain\Library\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Public catalog entry (docs/02 section 5.5). Existence in this catalog is
 * metadata only — it never implies a right to redistribute the underlying
 * file; `file_path` (private storage) is only set once that right is
 * confirmed, and is served through a policy-checked signed URL, not this
 * catalog listing.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $title
 * @property string|null $author
 * @property string|null $isbn
 * @property string|null $grade
 * @property string|null $subject
 * @property bool $is_required
 * @property string $access_scope
 * @property string|null $external_url
 * @property string|null $file_path
 */
#[Fillable(['title', 'author', 'isbn', 'grade', 'subject', 'is_required', 'access_scope', 'external_url', 'file_path'])]
class LibraryResource extends Model
{
    use BelongsToTenant;

    public const SCOPE_CATALOG_ONLY = 'catalog_only';

    public const SCOPE_LOAN = 'loan';

    public const SCOPE_DIGITAL = 'digital';

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
        ];
    }
}
