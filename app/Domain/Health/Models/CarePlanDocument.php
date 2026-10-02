<?php

namespace App\Domain\Health\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $student_id
 * @property string $title
 * @property string $storage_path
 * @property string $mime
 * @property int $size
 * @property string $checksum
 * @property int $uploaded_by
 * @property Carbon|null $created_at
 */
#[Fillable(['student_id', 'title', 'storage_path', 'mime', 'size', 'checksum', 'uploaded_by'])]
class CarePlanDocument extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'title' => 'encrypted',
            'size' => 'integer',
        ];
    }
}
