<?php

namespace App\Domain\Assessment\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $grade_scale_id
 * @property string $min_percent
 * @property string $label
 */
#[Fillable(['grade_scale_id', 'min_percent', 'label'])]
class GradeScaleBand extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'min_percent' => 'decimal:2',
        ];
    }
}
