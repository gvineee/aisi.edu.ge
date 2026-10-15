<?php

namespace App\Domain\Finance\Models;

use App\Domain\Academics\Models\Student;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A student's enrolment on a fee plan (the signed tuition contract), with
 * an optional percentage discount and its reason.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $student_id
 * @property int $fee_plan_id
 * @property string|null $contract_number
 * @property Carbon|null $signed_on
 * @property int $discount_percent
 * @property string|null $discount_reason
 * @property string $status
 * @property Carbon|null $ended_on
 * @property string|null $notes
 * @property int $created_by
 */
#[Fillable(['student_id', 'fee_plan_id', 'contract_number', 'signed_on', 'discount_percent', 'discount_reason', 'status', 'ended_on', 'notes', 'created_by'])]
class StudentFeeContract extends Model
{
    use BelongsToTenant;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ENDED = 'ended';

    protected function casts(): array
    {
        return ['signed_on' => 'date', 'ended_on' => 'date', 'discount_percent' => 'integer'];
    }

    /** Installment amount after the discount, rounded half up to a tetri. */
    public function netAmount(int $amountMinor): int
    {
        return intdiv($amountMinor * (100 - $this->discount_percent) + 50, 100);
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * @return BelongsTo<FeePlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(FeePlan::class, 'fee_plan_id');
    }

    /**
     * @return HasMany<FeeCharge, $this>
     */
    public function charges(): HasMany
    {
        return $this->hasMany(FeeCharge::class);
    }
}
