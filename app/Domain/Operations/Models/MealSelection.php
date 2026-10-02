<?php

namespace App\Domain\Operations\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A guardian opting a student out of lunch on a given day.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $student_id
 * @property Carbon $served_on
 * @property string $choice
 * @property int $selected_by
 */
#[Fillable(['student_id', 'served_on', 'choice', 'selected_by'])]
class MealSelection extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'served_on' => 'date',
        ];
    }
}
