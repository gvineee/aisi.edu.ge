<?php

namespace App\Domain\Platform\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One resumable onboarding wizard step for a tenant.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $step
 * @property Carbon|null $completed_at
 * @property int|null $completed_by
 */
#[Fillable(['tenant_id', 'step', 'completed_at', 'completed_by'])]
class TenantOnboardingStep extends Model
{
    /**
     * Wizard order (CLAUDE-PLATFORM-MODULES §14).
     *
     * @var array<int, string>
     */
    public const STEPS = ['identity', 'domain', 'brand', 'locale', 'academic_year', 'staff_invites', 'modules', 'review'];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
        ];
    }
}
