<?php

namespace App\Domain\Academics\Actions;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Governance\AuditLogger;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Creates the first (or a later) `academic_years` row for a tenant. Before
 * this action existed there was no route/controller anywhere that could
 * create one — the only writer was TenantSeeder/ProductionSeeder — so a real
 * admin on a real production tenant had no way to set up the school
 * structure a teacher invitation or an Assignment ultimately depends on.
 * `is_current` is exclusive per tenant: setting a new year current unsets
 * every other year in the same transaction, never leaving two rows both
 * marked current.
 */
class CreateAcademicYear
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function handle(
        int $tenantId,
        User $actor,
        string $name,
        Carbon $startsOn,
        Carbon $endsOn,
        bool $isCurrent,
    ): AcademicYear {
        return DB::transaction(function () use ($tenantId, $actor, $name, $startsOn, $endsOn, $isCurrent) {
            if ($isCurrent) {
                AcademicYear::query()->where('tenant_id', $tenantId)->update(['is_current' => false]);
            }

            $year = new AcademicYear([
                'name' => $name,
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'is_current' => $isCurrent,
            ]);
            $year->tenant_id = $tenantId;
            $year->save();

            $this->auditLogger->record($tenantId, 'academic_year.created', $year, $actor->id, [
                'name' => $year->name,
            ]);

            return $year;
        });
    }
}
