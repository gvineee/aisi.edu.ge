<?php

namespace App\Domain\PickupConsent\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One release of a child at pickup — verified by code, or an override by a
 * senior staff member with a stated reason.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $student_id
 * @property int|null $pickup_code_id
 * @property string $method
 * @property string $released_to
 * @property string|null $reason
 * @property int $staff_id
 * @property Carbon|null $created_at
 */
#[Fillable(['student_id', 'pickup_code_id', 'method', 'released_to', 'reason', 'staff_id'])]
class PickupEvent extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    public const METHOD_CODE = 'code';

    public const METHOD_OVERRIDE = 'override';
}
