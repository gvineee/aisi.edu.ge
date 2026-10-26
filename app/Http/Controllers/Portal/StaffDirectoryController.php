<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Academics\Actions\LinkStaffAccount;
use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\StaffMember;
use App\Domain\Academics\Models\TeacherAssignment;
use App\Domain\Governance\AuditLogger;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Timetable\Models\Lesson;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The staff directory: every person who teaches or works at the school,
 * with their weekly workload by class and subject, and which login (if
 * any) they use. Administrators add people, link a login to them (never
 * guessed from a name) and mark leavers inactive; the accountant reads.
 */
class StaffDirectoryController extends Controller
{
    /** @var list<string> */
    public const VIEW_ROLES = [
        TenantMembership::ROLE_ADMIN,
        TenantMembership::ROLE_DIRECTOR,
        TenantMembership::ROLE_ACADEMIC_MANAGER,
        TenantMembership::ROLE_ACCOUNTANT,
    ];

    /** @var list<string> */
    private const MANAGE_ROLES = [
        TenantMembership::ROLE_ADMIN,
        TenantMembership::ROLE_ACADEMIC_MANAGER,
    ];

    public function index(Request $request, CurrentTenant $currentTenant): Response
    {
        $tenantId = $currentTenant->get()->id;
        $this->authorizeRoles($request, $tenantId, self::VIEW_ROLES);

        $years = AcademicYear::query()->where('tenant_id', $tenantId)->orderByDesc('starts_on')->get();
        $year = $years->firstWhere('id', $request->integer('year')) ?? $years->firstWhere('is_current', true) ?? $years->first();

        $staff = StaffMember::query()->where('tenant_id', $tenantId)->with('user')->orderBy('full_name')->get();
        $workloads = $year === null ? collect() : TeacherAssignment::query()
            ->where('tenant_id', $tenantId)->whereNotNull('staff_member_id')
            ->whereHas('schoolClass', fn ($q) => $q->where('academic_year_id', $year->id))
            ->with(['schoolClass:id,name', 'subjectModel:id,name'])
            ->get()
            ->groupBy('staff_member_id');
        $lessonsPerWeek = $year === null ? collect() : Lesson::query()
            ->where('tenant_id', $tenantId)->where('academic_year_id', $year->id)->whereNotNull('staff_member_id')
            ->get(['staff_member_id', 'timetable_version_id', 'day_of_week', 'period_number', 'joint_key', 'id'])
            ->groupBy('timetable_version_id')
            ->map(fn ($rows) => $rows->groupBy('staff_member_id')->map(fn ($own) => $own->unique(fn (Lesson $l) => $l->joint_key ?? "lesson:{$l->id}")->count()));

        $linkedUserIds = $staff->pluck('user_id')->filter();
        $members = User::query()
            ->whereIn('id', TenantMembership::query()->where('tenant_id', $tenantId)->where('is_active', true)
                ->whereNotIn('role', [TenantMembership::ROLE_STUDENT, TenantMembership::ROLE_GUARDIAN])->select('user_id'))
            ->whereNotIn('id', $linkedUserIds)
            ->orderBy('name')->get(['id', 'name', 'email']);

        return Inertia::render('portal/staff/index', [
            'years' => $years->map(fn (AcademicYear $y) => ['id' => $y->id, 'name' => $y->name])->values(),
            'yearId' => $year?->id,
            'canManage' => TenantMembership::userHasAnyActiveRole($tenantId, $request->user()->id, self::MANAGE_ROLES),
            'staff' => $staff->map(function (StaffMember $member) use ($workloads, $lessonsPerWeek) {
                $own = $workloads->get($member->id, collect());

                return [
                    'id' => $member->id,
                    'fullName' => $member->full_name,
                    'shortName' => $member->short_name,
                    'isPlaceholder' => $member->is_placeholder,
                    'status' => $member->status,
                    'account' => $member->user === null ? null : ['id' => $member->user->id, 'name' => $member->user->name, 'email' => $member->user->email],
                    // A joint lesson is one hour for the teacher, however many classes attend.
                    'weeklyHours' => (int) $own->unique(fn (TeacherAssignment $a) => $a->joint_key ?? "assignment:{$a->id}")->sum('weekly_hours'),
                    'lessonsPerWeek' => (int) $lessonsPerWeek->map(fn ($byStaff) => $byStaff->get($member->id, 0))->max(),
                    'workload' => $own->map(fn (TeacherAssignment $a) => [
                        'className' => $a->schoolClass->name ?? '',
                        'subjectName' => $a->subjectModel->name ?? (string) $a->subject,
                        'weeklyHours' => $a->weekly_hours,
                    ])->sortBy('className', SORT_NATURAL)->values(),
                ];
            })->values(),
            'members' => $members->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])->values(),
        ]);
    }

    public function store(Request $request, CurrentTenant $currentTenant, AuditLogger $auditLogger): RedirectResponse
    {
        $tenantId = $currentTenant->get()->id;
        $this->authorizeRoles($request, $tenantId, self::MANAGE_ROLES);
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:160'],
            'short_name' => ['nullable', 'string', 'max:40'],
        ]);

        $member = new StaffMember(['full_name' => trim($data['full_name']), 'short_name' => $data['short_name'] ?? null, 'status' => StaffMember::STATUS_ACTIVE]);
        $member->tenant_id = $tenantId;
        $member->save();
        $auditLogger->record($tenantId, 'staff.created', $member, $request->user()->id);

        return back()->with('toast', ['type' => 'success', 'message' => 'თანამშრომელი დაემატა.']);
    }

    public function update(Request $request, CurrentTenant $currentTenant, StaffMember $staffMember, AuditLogger $auditLogger): RedirectResponse
    {
        $tenantId = $currentTenant->get()->id;
        abort_unless($staffMember->tenant_id === $tenantId, 404);
        $this->authorizeRoles($request, $tenantId, self::MANAGE_ROLES);
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:160'],
            'short_name' => ['nullable', 'string', 'max:40'],
            'status' => ['required', 'in:'.StaffMember::STATUS_ACTIVE.','.StaffMember::STATUS_INACTIVE],
        ]);

        $before = $staffMember->only(['full_name', 'short_name', 'status']);
        $staffMember->update(['full_name' => trim($data['full_name']), 'short_name' => $data['short_name'] ?? null, 'status' => $data['status']]);
        $auditLogger->record($tenantId, 'staff.updated', $staffMember, $request->user()->id, ['changed' => array_keys(array_diff_assoc($staffMember->only(['full_name', 'short_name', 'status']), $before))]);

        return back()->with('toast', ['type' => 'success', 'message' => 'შენახულია.']);
    }

    public function link(Request $request, CurrentTenant $currentTenant, StaffMember $staffMember, LinkStaffAccount $action): RedirectResponse
    {
        $tenantId = $currentTenant->get()->id;
        abort_unless($staffMember->tenant_id === $tenantId, 404);
        $this->authorizeRoles($request, $tenantId, self::MANAGE_ROLES);
        $data = $request->validate(['user_id' => ['required', 'integer']]);

        $user = User::query()->find((int) $data['user_id']);
        abort_if($user === null, 422);
        $action->link($staffMember, $user, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'ანგარიში მიება — გაკვეთილები და დატვირთვა ამ მასწავლებლის პორტალში გამოჩნდება.']);
    }

    public function unlink(Request $request, CurrentTenant $currentTenant, StaffMember $staffMember, LinkStaffAccount $action): RedirectResponse
    {
        $tenantId = $currentTenant->get()->id;
        abort_unless($staffMember->tenant_id === $tenantId, 404);
        $this->authorizeRoles($request, $tenantId, self::MANAGE_ROLES);
        $action->unlink($staffMember, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'ანგარიში მოიხსნა.']);
    }

    /**
     * @param  list<string>  $roles
     */
    private function authorizeRoles(Request $request, int $tenantId, array $roles): void
    {
        abort_unless(TenantMembership::userHasAnyActiveRole($tenantId, $request->user()->id, $roles), 403);
    }
}
