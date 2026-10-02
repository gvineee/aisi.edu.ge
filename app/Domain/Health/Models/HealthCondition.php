<?php

namespace App\Domain\Health\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $student_id
 * @property string $name
 * @property string $severity
 * @property string|null $action_plan
 * @property bool $show_alert_to_staff
 * @property string|null $staff_alert
 * @property int|null $updated_by
 */
#[Fillable(['student_id', 'name', 'severity', 'action_plan', 'show_alert_to_staff', 'staff_alert', 'updated_by'])]
class HealthCondition extends Model
{
    use BelongsToTenant;

    /**
     * @var array<int, string>
     */
    public const SEVERITIES = ['low', 'medium', 'high'];

    protected function casts(): array
    {
        return [
            'name' => 'encrypted',
            'action_plan' => 'encrypted',
            'show_alert_to_staff' => 'boolean',
            'staff_alert' => 'encrypted',
        ];
    }
}
