<?php

namespace App\Domain\Health\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $student_id
 * @property Carbon $occurred_at
 * @property string $summary
 * @property string|null $action_taken
 * @property Carbon|null $guardian_notified_at
 * @property int $reported_by
 */
#[Fillable(['student_id', 'occurred_at', 'summary', 'action_taken', 'guardian_notified_at', 'reported_by'])]
class HealthIncident extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'summary' => 'encrypted',
            'action_taken' => 'encrypted',
            'guardian_notified_at' => 'datetime',
        ];
    }
}
