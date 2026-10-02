<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Academics\Models\Student;
use App\Domain\Governance\AuditLogger;
use App\Domain\Operations\Actions\ManageClubs;
use App\Domain\Operations\Models\Club;
use App\Domain\Operations\Models\ClubEnrollment;
use App\Domain\Operations\Models\ClubSession;
use App\Domain\Operations\Models\ClubWaitlistEntry;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\ResolvesOperationsAccess;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "კლუბები" (CLAUDE-PLATFORM-MODULES §11). Management creates clubs and
 * sees rosters; a club's leader sees its roster; guardians enroll their
 * own children (capacity + ordered waitlist enforced in ManageClubs).
 */
class ClubController extends Controller
{
    use ResolvesOperationsAccess;

    public function index(Request $request, CurrentTenant $currentTenant): Response
    {
        $tenant = $currentTenant->get();
        $user = $request->user();
        $isManagement = $this->isManagement($tenant->id, $user);
        $children = $this->ownChildren($tenant->id, $user);
        $isTeacher = TenantMembership::userHasActiveRole($tenant->id, $user->id, TenantMembership::ROLE_TEACHER);
        abort_unless($isManagement || $children->isNotEmpty() || $isTeacher, 403);

        $clubs = Club::query()->where('tenant_id', $tenant->id)->with('sessions', 'leader')->orderBy('name')->get();
        $enrolledCounts = ClubEnrollment::query()->where('tenant_id', $tenant->id)->where('status', ClubEnrollment::STATUS_ENROLLED)
            ->selectRaw('club_id, count(*) as aggregate')->groupBy('club_id')->pluck('aggregate', 'club_id');

        $childIds = $children->pluck('id');
        $childEnrollments = ClubEnrollment::query()->where('tenant_id', $tenant->id)->whereIn('student_id', $childIds)->where('status', ClubEnrollment::STATUS_ENROLLED)->get();
        $childWaits = ClubWaitlistEntry::query()->where('tenant_id', $tenant->id)->whereIn('student_id', $childIds)->where('status', ClubWaitlistEntry::STATUS_WAITING)->get();

        return Inertia::render('portal/clubs/index', [
            'isManagement' => $isManagement,
            'clubs' => $clubs->map(fn (Club $club) => [
                'id' => $club->id,
                'name' => $club->name,
                'description' => $club->description,
                'leaderName' => $club->leader?->name,
                'capacity' => $club->capacity,
                'enrolled' => (int) ($enrolledCounts[$club->id] ?? 0),
                'feeMinor' => $club->fee_minor,
                'currency' => $club->currency,
                'isOpen' => $club->is_open,
                'canSeeRoster' => $isManagement || $club->leader_id === $user->id,
                'sessions' => $club->sessions->map(fn (ClubSession $session) => [
                    'dayOfWeek' => $session->day_of_week,
                    'startsAt' => substr($session->starts_at, 0, 5),
                    'endsAt' => substr($session->ends_at, 0, 5),
                    'location' => $session->location,
                ])->values()->all(),
                'children' => $children->map(function (Student $child) use ($club, $childEnrollments, $childWaits) {
                    $wait = $childWaits->where('club_id', $club->id)->firstWhere('student_id', $child->id);

                    return [
                        'id' => $child->id,
                        'name' => $child->fullName(),
                        'status' => $childEnrollments->where('club_id', $club->id)->contains('student_id', $child->id)
                            ? 'enrolled'
                            : ($wait !== null ? 'waitlisted' : 'none'),
                        'waitPosition' => $wait === null ? null : ClubWaitlistEntry::query()
                            ->where('club_id', $club->id)->where('status', ClubWaitlistEntry::STATUS_WAITING)->where('id', '<=', $wait->id)->count(),
                    ];
                })->values()->all(),
            ])->values(),
            'teachers' => $isManagement
                ? User::query()->whereIn('id', TenantMembership::query()->where('tenant_id', $tenant->id)->where('role', TenantMembership::ROLE_TEACHER)->where('is_active', true)->select('user_id'))
                    ->orderBy('name')->get(['id', 'name'])->map(fn (User $teacher) => ['id' => $teacher->id, 'name' => $teacher->name])->values()
                : [],
        ]);
    }

    public function store(Request $request, CurrentTenant $currentTenant, AuditLogger $auditLogger): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($this->isManagement($tenant->id, $request->user()), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'capacity' => ['required', 'integer', 'min:1', 'max:500'],
            'fee' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'leader_id' => ['nullable', 'integer'],
            'sessions' => ['array', 'max:7'],
            'sessions.*.day_of_week' => ['required', 'integer', 'between:1,7'],
            'sessions.*.starts_at' => ['required', 'date_format:H:i'],
            'sessions.*.ends_at' => ['required', 'date_format:H:i', 'after:sessions.*.starts_at'],
            'sessions.*.location' => ['nullable', 'string', 'max:255'],
        ]);

        if (! empty($data['leader_id'])) {
            abort_unless(TenantMembership::userHasActiveRole($tenant->id, (int) $data['leader_id'], TenantMembership::ROLE_TEACHER), 422);
        }

        DB::transaction(function () use ($tenant, $data, $request, $auditLogger) {
            $club = new Club([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'capacity' => (int) $data['capacity'],
                // Money as integer tetri.
                'fee_minor' => isset($data['fee']) ? (int) round(((float) $data['fee']) * 100) : null,
                'currency' => 'GEL',
                'leader_id' => $data['leader_id'] ?? null,
                'is_open' => true,
            ]);
            $club->tenant_id = $tenant->id;
            $club->save();

            foreach ($data['sessions'] ?? [] as $session) {
                $row = new ClubSession(['club_id' => $club->id] + $session);
                $row->tenant_id = $tenant->id;
                $row->save();
            }

            $auditLogger->record($tenant->id, 'club.created', $club, $request->user()->id);
        });

        return back();
    }

    public function updateCapacity(Request $request, CurrentTenant $currentTenant, Club $club, ManageClubs $clubs): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($club->tenant_id === $tenant->id, 404);
        abort_unless($this->isManagement($tenant->id, $request->user()), 403);
        $request->validate(['capacity' => ['required', 'integer', 'min:1', 'max:500']]);

        $clubs->updateCapacity($club, $request->integer('capacity'), $request->user());

        return back();
    }

    public function roster(Request $request, CurrentTenant $currentTenant, Club $club): Response
    {
        $tenant = $currentTenant->get();
        abort_unless($club->tenant_id === $tenant->id, 404);
        $user = $request->user();
        abort_unless($this->isManagement($tenant->id, $user) || ($club->leader_id === $user->id && TenantMembership::userHasActiveRole($tenant->id, $user->id, TenantMembership::ROLE_TEACHER)), 403);

        return Inertia::render('portal/clubs/roster', [
            'club' => ['id' => $club->id, 'name' => $club->name, 'capacity' => $club->capacity],
            'enrolled' => ClubEnrollment::query()->where('club_id', $club->id)->where('status', ClubEnrollment::STATUS_ENROLLED)->with('student.schoolClass')->get()
                ->map(fn (ClubEnrollment $e) => ['studentName' => $e->student->fullName(), 'className' => $e->student->schoolClass?->name])->sortBy('studentName')->values(),
            'waitlist' => ClubWaitlistEntry::query()->where('club_id', $club->id)->where('status', ClubWaitlistEntry::STATUS_WAITING)->orderBy('id')->with('student.schoolClass')->get()
                ->map(fn (ClubWaitlistEntry $w) => ['studentName' => $w->student->fullName(), 'className' => $w->student->schoolClass?->name])->values(),
        ]);
    }

    public function join(Request $request, CurrentTenant $currentTenant, Club $club, ManageClubs $clubs): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($club->tenant_id === $tenant->id, 404);
        $request->validate(['student_id' => ['required', 'integer']]);

        $child = $this->ownChild($tenant->id, $request->user(), $request->integer('student_id'));
        abort_unless($child !== null, 403);

        $result = $clubs->join($club, $child, $request->user());

        return back()->with('clubResult', $result);
    }

    public function leave(Request $request, CurrentTenant $currentTenant, Club $club, ManageClubs $clubs): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($club->tenant_id === $tenant->id, 404);
        $request->validate(['student_id' => ['required', 'integer']]);

        $user = $request->user();
        $student = $this->ownChild($tenant->id, $user, $request->integer('student_id'))
            ?? ($this->isManagement($tenant->id, $user)
                ? Student::query()->where('tenant_id', $tenant->id)->whereKey($request->integer('student_id'))->first()
                : null);
        abort_unless($student !== null, 403);

        $clubs->leave($club, $student, $user);

        return back();
    }
}
