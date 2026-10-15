<?php

namespace App\Domain\Finance\Models;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A fee schedule the school defines (e.g. a year's tuition), split into
 * installments. Amounts are the school's own — nothing is seeded.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $academic_year_id
 * @property string $name
 * @property string $currency
 * @property bool $is_active
 * @property int $created_by
 */
#[Fillable(['academic_year_id', 'name', 'currency', 'is_active', 'created_by'])]
class FeePlan extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @return HasMany<FeePlanInstallment, $this>
     */
    public function installments(): HasMany
    {
        return $this->hasMany(FeePlanInstallment::class)->orderBy('due_on')->orderBy('id');
    }

    /**
     * @return HasMany<StudentFeeContract, $this>
     */
    public function contracts(): HasMany
    {
        return $this->hasMany(StudentFeeContract::class);
    }

    /**
     * @return BelongsTo<AcademicYear, $this>
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }
}
