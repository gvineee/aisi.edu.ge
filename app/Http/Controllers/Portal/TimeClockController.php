<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Governance\AuditLogger;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Timetable\Actions\StaffTimeClock;
use App\Domain\Timetable\Models\StaffTimeEntry;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * „სამუშაო დრო“: staff mark arrival and departure; management and the
 * accountant see the month per person, add or correct entries with a
 * reason, and export for payroll.
 */
class TimeClockController extends Controller
{
    /** @var list<string> */
    public const STAFF_ROLES = [
        TenantMembership::ROLE_TEACHER,
        TenantMembership::ROLE_ACADEMIC_MANAGER,
        TenantMembership::ROLE_ACCOUNTANT,
        TenantMembership::ROLE_EDITOR,
        TenantMembership::ROLE_LIBRARIAN,
        TenantMembership::ROLE_DIRECTOR,
        TenantMembership::ROLE_ADMIN,
    ];

    /** @var list<string> */
    public const MANAGE_ROLES = [
        TenantMembership::ROLE_ADMIN,
        TenantMembership::ROLE_DIRECTOR,
        TenantMembership::ROLE_ACADEMIC_MANAGER,
        TenantMembership::ROLE_ACCOUNTANT,
    ];

    public function __construct(private readonly StaffTimeClock $clock) {}

    public function index(Request $request, CurrentTenant $currentTenant): Response
    {
        $tenant = $currentTenant->get();
        $user = $request->user();
        abort_unless(TenantMembership::userHasAnyActiveRole($tenant->id, $user->id, self::STAFF_ROLES), 403);
        $isManager = TenantMembership::userHasAnyActiveRole($tenant->id, $user->id, self::MANAGE_ROLES);
        [$from, $to] = $this->month($request, $tenant->timezone);

        $open = $this->clock->open($tenant->id, $user->id);
        $staff = $isManager ? $this->staff($tenant->id) : collect();
        $selectedId = $isManager ? ($staff->firstWhere('id', $request->integer('user'))['id'] ?? null) : null;

        return Inertia::render('portal/time-clock/index', [
            'month' => $from->format('Y-m'),
            'timezone' => $tenant->timezone,
            'open' => $open === null || $this->clock->isStale($open) ? null : ['id' => $open->id, 'clockInAt' => $open->clock_in_at->toIso8601String()],
            'mine' => $this->entries($tenant->id, $user->id, $from, $to),
            'isManager' => $isManager,
            'staff' => $isManager ? $staff->values() : [],
            'summary' => $isManager ? collect($this->clock->summary($tenant->id, $from, $to))
                ->map(fn (array $row) => $row + ['name' => $staff->firstWhere('id', $row['userId'])['name'] ?? '—'])
                ->sortBy('name')->values() : [],
            'selected' => $selectedId === null ? null : [
                'userId' => $selectedId,
                'name' => $staff->firstWhere('id', $selectedId)['name'] ?? '',
                'entries' => $this->entries($tenant->id, $selectedId, $from, $to),
            ],
        ]);
    }

    public function clockIn(Request $request, CurrentTenant $currentTenant): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless(TenantMembership::userHasAnyActiveRole($tenant->id, $request->user()->id, self::STAFF_ROLES), 403);
        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;
        $this->clock->clockIn($tenant, $request->user(), $note === null ? null : trim($note));

        return back()->with('toast', ['type' => 'success', 'message' => 'მოსვლა მონიშნულია.']);
    }

    public function clockOut(Request $request, CurrentTenant $currentTenant): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless(TenantMembership::userHasAnyActiveRole($tenant->id, $request->user()->id, self::STAFF_ROLES), 403);
        $this->clock->clockOut($tenant, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'წასვლა მონიშნულია.']);
    }

    public function saveEntry(Request $request, CurrentTenant $currentTenant, ?StaffTimeEntry $staffTimeEntry = null): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless(TenantMembership::userHasAnyActiveRole($tenant->id, $request->user()->id, self::MANAGE_ROLES), 403);
        abort_if($staffTimeEntry !== null && $staffTimeEntry->tenant_id !== $tenant->id, 404);
        $staffIds = $this->staff($tenant->id)->pluck('id')->all();
        $data = $request->validate([
            'user_id' => [$staffTimeEntry === null ? 'required' : 'nullable', 'integer', Rule::in($staffIds)],
            'clock_in_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'clock_out_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        // The form's times are school-local.
        $in = Carbon::createFromFormat('Y-m-d\TH:i', $data['clock_in_at'], $tenant->timezone);
        $out = empty($data['clock_out_at']) ? null : Carbon::createFromFormat('Y-m-d\TH:i', $data['clock_out_at'], $tenant->timezone);
        $this->clock->correct($tenant, $staffTimeEntry, $staffTimeEntry->user_id ?? (int) $data['user_id'], $in, $out, trim($data['reason']), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'ჩანაწერი შენახულია.']);
    }

    public function export(Request $request, CurrentTenant $currentTenant, AuditLogger $auditLogger): StreamedResponse
    {
        $tenant = $currentTenant->get();
        abort_unless(TenantMembership::userHasAnyActiveRole($tenant->id, $request->user()->id, self::MANAGE_ROLES), 403);
        [$from, $to] = $this->month($request, $tenant->timezone);
        $staff = $this->staff($tenant->id);
        $entries = StaffTimeEntry::query()->where('tenant_id', $tenant->id)
            ->whereDate('work_date', '>=', $from->toDateString())->whereDate('work_date', '<=', $to->toDateString())
            ->orderBy('work_date')->orderBy('clock_in_at')->get();
        $auditLogger->record($tenant->id, 'time_clock.exported', $tenant, $request->user()->id, ['month' => $from->format('Y-m'), 'rows' => $entries->count()]);

        return response()->streamDownload(function () use ($entries, $staff, $tenant) {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF"); // Excel reads UTF-8 Georgian only with a BOM
            fputcsv($out, ['თანამშრომელი', 'თარიღი', 'მოსვლა', 'წასვლა', 'წუთი', 'შესწორება']);

            foreach ($entries as $entry) {
                fputcsv($out, [
                    $staff->firstWhere('id', $entry->user_id)['name'] ?? '—',
                    $entry->work_date->toDateString(),
                    $entry->clock_in_at->timezone($tenant->timezone)->format('H:i'),
                    $entry->clock_out_at?->timezone($tenant->timezone)->format('H:i') ?? '',
                    $entry->minutes() ?? '',
                    $entry->correction_reason ?? '',
                ]);
            }

            fclose($out);
        }, "time-clock-{$from->format('Y-m')}.csv", ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    /**
     * @return list<array{id: int, clockInAt: string, clockOutAt: string|null, minutes: int|null, source: string, note: string|null, correctionReason: string|null}>
     */
    private function entries(int $tenantId, int $userId, Carbon $from, Carbon $to): array
    {
        return array_values(StaffTimeEntry::query()->where('tenant_id', $tenantId)->where('user_id', $userId)
            ->whereDate('work_date', '>=', $from->toDateString())->whereDate('work_date', '<=', $to->toDateString())
            ->orderByDesc('clock_in_at')->get()
            ->map(fn (StaffTimeEntry $e) => [
                'id' => $e->id,
                'clockInAt' => $e->clock_in_at->toIso8601String(),
                'clockOutAt' => $e->clock_out_at?->toIso8601String(),
                'minutes' => $e->minutes(),
                'source' => $e->source,
                'note' => $e->note,
                'correctionReason' => $e->correction_reason,
            ])->all());
    }

    /**
     * Active staff members of the school, by name.
     *
     * @return Collection<int, array{id: int, name: string}>
     */
    private function staff(int $tenantId): Collection
    {
        $ids = TenantMembership::query()->where('tenant_id', $tenantId)->where('is_active', true)->whereIn('role', self::STAFF_ROLES)->distinct()->pluck('user_id');

        return User::query()->whereIn('id', $ids)->orderBy('name')->get(['id', 'name'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name]);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function month(Request $request, string $timezone): array
    {
        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $month = ! empty($data['month']) ? Carbon::createFromFormat('Y-m-d', $data['month'].'-01') : Carbon::now($timezone)->startOfMonth();

        return [$month->copy()->startOfMonth()->startOfDay(), $month->copy()->endOfMonth()->startOfDay()];
    }
}
