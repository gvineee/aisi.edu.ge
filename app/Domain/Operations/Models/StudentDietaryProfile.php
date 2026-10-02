<?php

namespace App\Domain\Operations\Models;

use App\Domain\Academics\Models\Student;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A student's allergen codes for the kitchen — never the health diagnosis.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $student_id
 * @property array<int, string> $allergens
 * @property int|null $updated_by
 */
#[Fillable(['student_id', 'allergens', 'updated_by'])]
class StudentDietaryProfile extends Model
{
    use BelongsToTenant;

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    protected function casts(): array
    {
        return [
            'allergens' => 'array',
        ];
    }
}
