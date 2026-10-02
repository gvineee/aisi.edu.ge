<?php

namespace App\Domain\Portfolio\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A recognition a teacher (of the student's class) or school leadership
 * awards to a student. Families see it only when shared.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $student_id
 * @property string $title
 * @property string|null $description
 * @property Carbon $awarded_on
 * @property bool $shared_with_family
 * @property int $awarded_by
 */
#[Fillable(['student_id', 'title', 'description', 'awarded_on', 'shared_with_family', 'awarded_by'])]
class Achievement extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'awarded_on' => 'date',
            'shared_with_family' => 'boolean',
        ];
    }
}
