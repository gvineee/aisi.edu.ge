<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Governance\AuditLogger;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Timetable\Actions\SaveBellSchedule;
use App\Domain\Timetable\Models\TimetableVersion;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The timetable's frame: the bell schedule of a year and the timetable
 * versions with their validity windows. One version per year is active at
 * a time; activating another archives the previous one.
 */
class TimetableSetupController extends Controller
{
    /**
     * @var array<int, string>
     */
    private const ACCESS_ROLES = [
        TenantMembership::ROLE_ACADEMIC_MANAGER,
        TenantMembership::ROLE_ADMIN,
    ];

    public function saveBellPeriods(Request $request, CurrentTenant $currentTenant, AcademicYear $academicYear, SaveBellSchedule $action): RedirectResponse
    {
        $this->authorizeYear($request, $currentTenant, $academicYear);

        $data = $request->validate([
            'periods' => ['array', 'max:15'],
            'periods.*.starts_at' => ['required', 'date_format:H:i'],
            'periods.*.ends_at' => ['required', 'date_format:H:i'],
        ]);

        $periods = array_values(array_map(
            fn (array $period) => ['starts_at' => (string) $period['starts_at'], 'ends_at' => (string) $period['ends_at']],
            (array) ($data['periods'] ?? []),
        ));
        $action->handle($academicYear, $request->user(), $periods);

        return back()->with('toast', ['type' => 'success', 'message' => 'ზარების განრიგი შენახულია.']);
    }

    public function storeVersion(Request $request, CurrentTenant $currentTenant, AcademicYear $academicYear, AuditLogger $auditLogger): RedirectResponse
    {
        $this->authorizeYear($request, $currentTenant, $academicYear);

        $data = $request->validate([
            'label' => ['required', 'string', 'max:120'],
            'source_version' => ['nullable', 'string', 'max:64'],
            'valid_from' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$academicYear->starts_on->toDateString(), 'before_or_equal:'.$academicYear->ends_on->toDateString()],
            'valid_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from', 'before_or_equal:'.$academicYear->ends_on->toDateString()],
        ]);

        $version = new TimetableVersion([
            'academic_year_id' => $academicYear->id,
            'label' => $data['label'],
            'source_version' => $data['source_version'] ?? null,
            'valid_from' => $data['valid_from'],
            'valid_to' => $data['valid_to'] ?? null,
            'status' => TimetableVersion::STATUS_DRAFT,
        ]);
        $version->tenant_id = $academicYear->tenant_id;
        $version->save();

        $auditLogger->record($version->tenant_id, 'timetable_version.created', $version, $request->user()->id, ['academic_year_id' => $academicYear->id]);

        return back()->with('toast', ['type' => 'success', 'message' => 'ცხრილის ვერსია დაემატა (სამუშაო).']);
    }

    public function activateVersion(Request $request, CurrentTenant $currentTenant, TimetableVersion $timetableVersion, AuditLogger $auditLogger): RedirectResponse
    {
        $tenantId = $currentTenant->get()->id;
        abort_unless($timetableVersion->tenant_id === $tenantId, 404);
        abort_unless(TenantMembership::userHasAnyActiveRole($tenantId, $request->user()->id, self::ACCESS_ROLES), 403);

        DB::transaction(function () use ($timetableVersion, $tenantId) {
            TimetableVersion::query()
                ->where('tenant_id', $tenantId)
                ->where('academic_year_id', $timetableVersion->academic_year_id)
                ->where('status', TimetableVersion::STATUS_ACTIVE)
                ->whereKeyNot($timetableVersion->id)
                ->update(['status' => TimetableVersion::STATUS_ARCHIVED, 'updated_at' => now()]);

            $timetableVersion->status = TimetableVersion::STATUS_ACTIVE;
            $timetableVersion->save();
        });

        $auditLogger->record($tenantId, 'timetable_version.activated', $timetableVersion, $request->user()->id, ['academic_year_id' => $timetableVersion->academic_year_id]);

        return back()->with('toast', ['type' => 'success', 'message' => 'ცხრილის ვერსია გააქტიურდა; წინა აქტიური ვერსია დაარქივდა.']);
    }

    private function authorizeYear(Request $request, CurrentTenant $currentTenant, AcademicYear $academicYear): void
    {
        $tenantId = $currentTenant->get()->id;
        abort_unless($academicYear->tenant_id === $tenantId, 404);
        abort_unless(TenantMembership::userHasAnyActiveRole($tenantId, $request->user()->id, self::ACCESS_ROLES), 403);
    }
}
