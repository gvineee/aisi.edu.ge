<?php

namespace App\Domain\Integrations\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A quarantined source row. Holds the source key and field name only —
 * never the raw value, which may be personal data.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $import_batch_id
 * @property int|null $row_number
 * @property string|null $source_key
 * @property string $kind
 * @property string|null $field
 * @property string $message
 */
#[Fillable(['tenant_id', 'import_batch_id', 'row_number', 'source_key', 'kind', 'field', 'message'])]
class ImportIssue extends Model
{
    use BelongsToTenant;
}
