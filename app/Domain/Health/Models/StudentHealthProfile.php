<?php

namespace App\Domain\Health\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $student_id
 * @property string|null $family_doctor
 * @property string|null $notes
 * @property int|null $updated_by
 */
#[Fillable(['student_id', 'family_doctor', 'notes', 'updated_by'])]
class StudentHealthProfile extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'family_doctor' => 'encrypted',
            'notes' => 'encrypted',
        ];
    }
}
