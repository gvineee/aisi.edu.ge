<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Calendar\CalendarFeed;
use App\Domain\Calendar\Models\SchoolEvent;
use App\Domain\Governance\AuditLogger;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\PortalContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The school calendar („კალენდარი“): a month of events for whoever is
 * signed in — CalendarFeed decides what each role may see — and, for
 * management, adding, changing and removing events. Holidays are edited
 * on the worked-hours page and only shown here.
 */
class SchoolCalendarController extends Controller
{
    /** @var list<string> */
    public const MANAGE_ROLES = [
        TenantMembership::ROLE_ADMIN,
        TenantMembership::ROLE_DIRECTOR,
        TenantMembership::ROLE_ACADEMIC_MANAGER,
    ];

    public function index(Request $request, CurrentTenant $currentTenant, PortalContext $portalContext, CalendarFeed $feed): Response
    {
        $tenant = $currentTenant->get();
        $user = $request->user();
        $role = $portalContext->resolveActiveRole($request, $tenant->id, $user->id);
        abort_if($role === null, 403);

        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $month = ! empty($data['month'])
            ? Carbon::createFromFormat('Y-m-d', $data['month'].'-01')->startOfDay()
            : Carbon::now($tenant->timezone)->startOfMonth()->startOfDay();
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth()->startOfDay();
        $canManage = in_array($role, self::MANAGE_ROLES, true)
            && TenantMembership::userHasAnyActiveRole($tenant->id, $user->id, self::MANAGE_ROLES);

        return Inertia::render('portal/calendar/index', [
            'month' => $from->format('Y-m'),
            'today' => Carbon::now($tenant->timezone)->toDateString(),
            'entries' => $feed->for($tenant->id, $role, $user->id, $from, $to),
            'canManage' => $canManage,
            'classes' => $canManage ? $this->classesFor($tenant->id, $from) : [],
        ]);
    }

    public function store(Request $request, CurrentTenant $currentTenant, AuditLogger $auditLogger): RedirectResponse
    {
        $tenantId = $currentTenant->get()->id;
        $this->authorizeManage($request, $tenantId);
        $data = $this->validated($request, $tenantId);

        $event = DB::transaction(function () use ($data, $tenantId, $request) {
            $event = new SchoolEvent($data['values'] + ['created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
            $event->tenant_id = $tenantId;
            $event->save();
            $event->classes()->sync(array_fill_keys($data['classIds'], ['tenant_id' => $tenantId]));

            return $event;
        });
        $auditLogger->record($tenantId, 'calendar.event_created', $event, $request->user()->id, ['audience' => $event->audience, 'classes' => count($data['classIds'])]);

        return back()->with('toast', ['type' => 'success', 'message' => 'ღონისძიება დაემატა კალენდარში.']);
    }

    public function update(Request $request, CurrentTenant $currentTenant, SchoolEvent $schoolEvent, AuditLogger $auditLogger): RedirectResponse
    {
        $tenantId = $currentTenant->get()->id;
        abort_unless($schoolEvent->tenant_id === $tenantId, 404);
        $this->authorizeManage($request, $tenantId);
        $data = $this->validated($request, $tenantId);
        $before = $schoolEvent->only(['title', 'starts_on', 'ends_on', 'audience']);

        DB::transaction(function () use ($schoolEvent, $data, $tenantId, $request) {
            $schoolEvent->update($data['values'] + ['updated_by' => $request->user()->id]);
            $schoolEvent->classes()->sync(array_fill_keys($data['classIds'], ['tenant_id' => $tenantId]));
        });
        $auditLogger->record($tenantId, 'calendar.event_updated', $schoolEvent, $request->user()->id, ['before' => $before]);

        return back()->with('toast', ['type' => 'success', 'message' => 'ცვლილება შენახულია.']);
    }

    public function destroy(Request $request, CurrentTenant $currentTenant, SchoolEvent $schoolEvent, AuditLogger $auditLogger): RedirectResponse
    {
        $tenantId = $currentTenant->get()->id;
        abort_unless($schoolEvent->tenant_id === $tenantId, 404);
        $this->authorizeManage($request, $tenantId);
        $auditLogger->record($tenantId, 'calendar.event_deleted', $schoolEvent, $request->user()->id, ['title' => $schoolEvent->title, 'starts_on' => $schoolEvent->starts_on->toDateString()]);
        $schoolEvent->delete();

        return back()->with('toast', ['type' => 'success', 'message' => 'ღონისძიება წაიშალა.']);
    }

    /**
     * @return array{values: array<string, mixed>, classIds: list<int>}
     */
    private function validated(Request $request, int $tenantId): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category' => ['required', Rule::in(SchoolEvent::CATEGORIES)],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'starts_at' => ['nullable', 'date_format:H:i'],
            'ends_at' => ['nullable', 'date_format:H:i'],
            'location' => ['nullable', 'string', 'max:160'],
            'audience' => ['required', Rule::in(SchoolEvent::AUDIENCES)],
            'class_ids' => ['array', 'required_if:audience,'.SchoolEvent::AUDIENCE_CLASSES],
            'class_ids.*' => ['integer', Rule::exists('school_classes', 'id')->where('tenant_id', $tenantId)],
        ], [
            'class_ids.required_if' => 'აირჩიეთ მინიმუმ ერთი კლასი.',
            'ends_on.after_or_equal' => 'დასრულება დაწყებამდე ვერ იქნება.',
        ]);

        $startsOn = $data['starts_on'];
        $endsOn = $data['ends_on'] ?? $startsOn;
        $startsAt = $data['starts_at'] ?? null;
        $endsAt = $startsAt === null ? null : ($data['ends_at'] ?? null);

        if ($startsAt !== null && $endsAt !== null && $startsOn === $endsOn && $endsAt < $startsAt) {
            throw ValidationException::withMessages(['ends_at' => 'დასრულების დრო დაწყებამდე ვერ იქნება.']);
        }

        $year = AcademicYear::query()->where('tenant_id', $tenantId)
            ->whereDate('starts_on', '<=', $startsOn)->whereDate('ends_on', '>=', $startsOn)->first();
        $classIds = $data['audience'] === SchoolEvent::AUDIENCE_CLASSES
            ? array_values(array_unique(array_map('intval', $data['class_ids'] ?? [])))
            : [];

        return [
            'values' => [
                'academic_year_id' => $year?->id,
                'title' => trim($data['title']),
                'description' => isset($data['description']) ? trim($data['description']) : null,
                'category' => $data['category'],
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'location' => isset($data['location']) ? trim($data['location']) : null,
                'audience' => $data['audience'],
            ],
            'classIds' => $classIds,
        ];
    }

    /**
     * The classes of the year the month falls in, else the current year.
     *
     * @return list<array{id: int, name: string}>
     */
    private function classesFor(int $tenantId, Carbon $month): array
    {
        $year = AcademicYear::query()->where('tenant_id', $tenantId)
            ->whereDate('starts_on', '<=', $month->copy()->endOfMonth()->toDateString())
            ->whereDate('ends_on', '>=', $month->toDateString())
            ->orderByDesc('starts_on')->first()
            ?? AcademicYear::query()->where('tenant_id', $tenantId)->where('is_current', true)->first();

        if ($year === null) {
            return [];
        }

        return array_values(SchoolClass::query()->where('tenant_id', $tenantId)->where('academic_year_id', $year->id)->get(['id', 'name'])
            ->sortBy('name', SORT_NATURAL)
            ->map(fn (SchoolClass $class) => ['id' => $class->id, 'name' => $class->name])->all());
    }

    private function authorizeManage(Request $request, int $tenantId): void
    {
        abort_unless(TenantMembership::userHasAnyActiveRole($tenantId, $request->user()->id, self::MANAGE_ROLES), 403);
    }
}
