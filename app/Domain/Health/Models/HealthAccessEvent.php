<?php

namespace App\Domain\Health\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Append-only "who saw or changed which student's health data, on what
 * basis". Holds no health content.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $student_id
 * @property int $actor_id
 * @property string $action
 * @property string $access_basis
 * @property int|null $emergency_access_id
 * @property Carbon|null $created_at
 */
#[Fillable(['student_id', 'actor_id', 'action', 'access_basis', 'emergency_access_id'])]
class HealthAccessEvent extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;
}
