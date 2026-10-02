<?php

namespace App\Domain\Assessment\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A school-approved mapping from normalized percent to a final grade label.
 * Immutable once created — a rule change is a new version, so a past
 * assessment's grades never silently change meaning. The platform ships no
 * default scale: the grading policy is the school's decision (CLAUDE.md,
 * "questions not to invent").
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property int $version
 * @property bool $is_active
 * @property int|null $created_by
 */
#[Fillable(['name', 'version', 'is_active', 'created_by'])]
class GradeScale extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<GradeScaleBand, $this>
     */
    public function bands(): HasMany
    {
        return $this->hasMany(GradeScaleBand::class)->orderByDesc('min_percent');
    }
}
