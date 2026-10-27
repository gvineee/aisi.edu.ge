<?php

namespace App\Domain\Certificates\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A certificate, diploma or letter template. `title` and `body` may hold
 * placeholders — {name}, {class}, {year}, {date}, {school}, {number} —
 * filled in when a document is issued.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string $kind
 * @property string $title
 * @property string $body
 * @property string|null $signatory
 * @property string $orientation
 * @property bool $is_active
 * @property int|null $created_by
 */
#[Fillable(['name', 'kind', 'title', 'body', 'signatory', 'orientation', 'is_active', 'created_by'])]
class CertificateTemplate extends Model
{
    use BelongsToTenant;

    public const KINDS = ['certificate', 'diploma', 'letter'];

    public const PLACEHOLDERS = ['name', 'class', 'year', 'date', 'school', 'number'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
