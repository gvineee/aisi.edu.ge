<?php

namespace App\Domain\Assessment\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $competency_id
 * @property int $level
 * @property string $label
 * @property string|null $descriptor
 */
#[Fillable(['competency_id', 'level', 'label', 'descriptor'])]
class CompetencyLevel extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'level' => 'integer',
        ];
    }
}
