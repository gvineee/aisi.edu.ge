<?php

namespace App\Domain\Health\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Recorded exactly as the family/doctor provided it; the platform never
 * suggests or computes a dose.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $student_id
 * @property string $name
 * @property string|null $instructions
 * @property int|null $updated_by
 */
#[Fillable(['student_id', 'name', 'instructions', 'updated_by'])]
class Medication extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'name' => 'encrypted',
            'instructions' => 'encrypted',
        ];
    }
}
