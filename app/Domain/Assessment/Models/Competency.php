<?php

namespace App\Domain\Assessment\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A competency in the school's own framework (none is seeded — the
 * framework is the school's decision).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property bool $is_active
 * @property int|null $created_by
 */
#[Fillable(['code', 'name', 'description', 'is_active', 'created_by'])]
class Competency extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<CompetencyLevel, $this>
     */
    public function levels(): HasMany
    {
        return $this->hasMany(CompetencyLevel::class)->orderBy('level');
    }
}
