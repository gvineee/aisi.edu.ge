<?php

namespace App\Domain\Health\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $student_id
 * @property string $name
 * @property string|null $relationship
 * @property string $phone
 * @property int $priority
 * @property int|null $updated_by
 */
#[Fillable(['student_id', 'name', 'relationship', 'phone', 'priority', 'updated_by'])]
class EmergencyContact extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'name' => 'encrypted',
            'relationship' => 'encrypted',
            'phone' => 'encrypted',
            'priority' => 'integer',
        ];
    }
}
