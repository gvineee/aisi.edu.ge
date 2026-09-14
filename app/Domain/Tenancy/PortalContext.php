<?php

namespace App\Domain\Tenancy;

use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Resolves "which of the current user's real, active roles in this tenant
 * are they viewing the portal as right now" — the one place that answers
 * this question, shared by the dashboard (which screen to render), the
 * layout nav (what's visible), and the role-switch endpoint (what's a valid
 * switch target). A role hidden from navigation is never itself the
 * authorization boundary — every controller still re-checks
 * TenantMembership itself (docs/04 §6's explicit warning).
 */
class PortalContext
{
    public const SESSION_KEY = 'portal_active_role';

    /**
     * Roles ordered by which one a multi-role user probably wants to land
     * on first. Order is a UX default only, not a privilege ranking.
     *
     * @var array<int, string>
     */
    private const ROLE_PRIORITY = [
        TenantMembership::ROLE_ADMIN,
        TenantMembership::ROLE_DIRECTOR,
        TenantMembership::ROLE_ACADEMIC_MANAGER,
        TenantMembership::ROLE_TEACHER,
        TenantMembership::ROLE_ACCOUNTANT,
        TenantMembership::ROLE_EDITOR,
        TenantMembership::ROLE_GUARDIAN,
        TenantMembership::ROLE_STUDENT,
    ];

    /**
     * @var array<string, string>
     */
    private const ROLE_LABELS = [
        TenantMembership::ROLE_ADMIN => 'ადმინისტრატორი',
        TenantMembership::ROLE_DIRECTOR => 'დირექტორი',
        TenantMembership::ROLE_ACADEMIC_MANAGER => 'აკადემიური მენეჯერი',
        TenantMembership::ROLE_TEACHER => 'მასწავლებელი',
        TenantMembership::ROLE_ACCOUNTANT => 'ბუღალტერი',
        TenantMembership::ROLE_EDITOR => 'რედაქტორი',
        TenantMembership::ROLE_GUARDIAN => 'მშობელი',
        TenantMembership::ROLE_STUDENT => 'მოსწავლე',
    ];

    /**
     * Roles that get the "დოკუმენტები" nav destination — kept in sync with
     * DocumentController::STAFF_ROLES; duplicated as plain strings here
     * rather than importing that controller, since nav *visibility* is a UI
     * concern, not the authorization check itself (that check still lives,
     * and is re-run, in DocumentController).
     *
     * @var array<int, string>
     */
    private const DOCUMENT_ACCESS_ROLES = [
        TenantMembership::ROLE_TEACHER,
        TenantMembership::ROLE_ACADEMIC_MANAGER,
        TenantMembership::ROLE_ACCOUNTANT,
        TenantMembership::ROLE_EDITOR,
        TenantMembership::ROLE_DIRECTOR,
        TenantMembership::ROLE_ADMIN,
    ];

    /**
     * Roles that get the "CMS" nav destination — kept in sync with
     * CmsPageController::ACCESS_ROLES for the same reason as
     * DOCUMENT_ACCESS_ROLES above (nav visibility isn't the authorization
     * check; each CMS controller re-runs it).
     *
     * @var array<int, string>
     */
    private const CMS_ACCESS_ROLES = [
        TenantMembership::ROLE_EDITOR,
        TenantMembership::ROLE_ACADEMIC_MANAGER,
        TenantMembership::ROLE_DIRECTOR,
        TenantMembership::ROLE_ADMIN,
    ];

    /**
     * Roles that get the "წევრები" nav destination — kept in sync with
     * MemberController::ADMIN_ROLES, duplicated as plain strings for the
     * same reason as DOCUMENT_ACCESS_ROLES above (nav visibility is a UI
     * concern; MemberController re-checks this itself on every action).
     *
     * @var array<int, string>
     */
    private const MEMBER_ACCESS_ROLES = [
        TenantMembership::ROLE_DIRECTOR,
        TenantMembership::ROLE_ADMIN,
    ];

    /**
     * Roles that get the "სასწავლო წლები" nav destination — kept in sync
     * with AcademicStructureController::ACCESS_ROLES for the same reason as
     * DOCUMENT_ACCESS_ROLES above.
     *
     * @var array<int, string>
     */
    private const ACADEMIC_STRUCTURE_ACCESS_ROLES = [
        TenantMembership::ROLE_ACADEMIC_MANAGER,
        TenantMembership::ROLE_DIRECTOR,
        TenantMembership::ROLE_ADMIN,
    ];

    /**
     * Roles that get the "მასწავლებლები" nav destination — kept in sync
     * with TeacherController::ACCESS_ROLES for the same reason as
     * DOCUMENT_ACCESS_ROLES above.
     *
     * @var array<int, string>
     */
    private const TEACHER_MANAGEMENT_ACCESS_ROLES = [
        TenantMembership::ROLE_EDITOR,
        TenantMembership::ROLE_ACADEMIC_MANAGER,
        TenantMembership::ROLE_DIRECTOR,
        TenantMembership::ROLE_ADMIN,
    ];

    /**
     * Roles that get the "დავალებები" nav destination (Assignments &
     * Submissions) — the teacher-authoring view and the student
     * submission view respectively; the href picked in navItemsFor()
     * differs per role, but both re-check ownership/enrollment themselves
     * in AssignmentController/MyAssignmentsController.
     *
     * @var array<int, string>
     */
    private const ASSIGNMENT_ACCESS_ROLES = [
        TenantMembership::ROLE_TEACHER,
        TenantMembership::ROLE_STUDENT,
    ];

    /**
     * Roles that get the "ჩანაცვლებები" nav destination — kept in sync with
     * SubstitutionController::ACCESS_ROLES for the same reason as
     * DOCUMENT_ACCESS_ROLES above. A substitute teacher sees their assigned
     * coverage on the ordinary teacher dashboard instead, no nav item.
     *
     * @var array<int, string>
     */
    private const SUBSTITUTION_ACCESS_ROLES = [
        TenantMembership::ROLE_ACADEMIC_MANAGER,
        TenantMembership::ROLE_DIRECTOR,
        TenantMembership::ROLE_ADMIN,
    ];

    /**
     * Roles that get the "მიღების პროცესი" nav destination — kept in sync
     * with AdmissionsPipelineController::ACCESS_ROLES for the same reason as
     * DOCUMENT_ACCESS_ROLES above.
     *
     * @var array<int, string>
     */
    private const ADMISSIONS_PIPELINE_ACCESS_ROLES = [
        TenantMembership::ROLE_ACADEMIC_MANAGER,
        TenantMembership::ROLE_DIRECTOR,
        TenantMembership::ROLE_ADMIN,
    ];

    /**
     * Roles that get the "განრიგი" nav destination — kept in sync with
     * LessonController::ACCESS_ROLES for the same reason as
     * DOCUMENT_ACCESS_ROLES above (director is deliberately excluded here,
     * matching LessonController's own docblock: schedule authoring is the
     * academic manager's job, not the director's).
     *
     * @var array<int, string>
     */
    private const TIMETABLE_ACCESS_ROLES = [
        TenantMembership::ROLE_ACADEMIC_MANAGER,
        TenantMembership::ROLE_ADMIN,
    ];

    /**
     * Roles that get the "უფლებამოსილი პირები" (authorized pickup) nav
     * destination — kept in sync with PickupController, which re-checks
     * every action against the caller's own active GuardianLink rows
     * regardless of nav visibility.
     *
     * @var array<int, string>
     */
    private const PICKUP_ACCESS_ROLES = [
        TenantMembership::ROLE_GUARDIAN,
    ];

    /**
     * Roles that get the "თანხმობები" nav destination — kept in sync with
     * ConsentController::STAFF_ROLES plus the guardian role it also serves
     * (the same route renders a different screen per role); nav visibility
     * is not the authorization check, ConsentController re-runs it itself.
     *
     * @var array<int, string>
     */
    private const CONSENT_ACCESS_ROLES = [
        TenantMembership::ROLE_GUARDIAN,
        TenantMembership::ROLE_DIRECTOR,
        TenantMembership::ROLE_ADMIN,
    ];

    /**
     * @return array<int, string> active role strings, in ROLE_PRIORITY order
     */
    public function activeRoles(int $tenantId, int $userId): array
    {
        $held = TenantMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->pluck('role')
            ->all();

        return array_values(array_filter(self::ROLE_PRIORITY, fn (string $role) => in_array($role, $held, true)));
    }

    /**
     * Resolves the role this request should render as, persisting a
     * user-chosen role in the session so it survives to the next request.
     * Never trusts a client-supplied role directly — only ever narrows to
     * one already present in activeRoles().
     */
    public function resolveActiveRole(Request $request, int $tenantId, int $userId): ?string
    {
        $available = $this->activeRoles($tenantId, $userId);

        if ($available === []) {
            return null;
        }

        $sessionRole = $request->session()->get(self::SESSION_KEY);

        if (is_string($sessionRole) && in_array($sessionRole, $available, true)) {
            return $sessionRole;
        }

        return $available[0];
    }

    /**
     * Called by the role-switch endpoint only, after it has already
     * verified $role is one of the user's own activeRoles().
     */
    public function setActiveRole(Request $request, string $role): void
    {
        $request->session()->put(self::SESSION_KEY, $role);
    }

    /**
     * @return array<int, array{key: string, label: string, href: string, icon: string}>
     */
    public function navItemsFor(string $activeRole): array
    {
        $items = [
            ['key' => 'dashboard', 'label' => 'დღეს', 'href' => route('dashboard'), 'icon' => 'home'],
            ['key' => 'messages', 'label' => 'შეტყობინებები', 'href' => route('messages.index'), 'icon' => 'messages'],
        ];

        if (in_array($activeRole, self::DOCUMENT_ACCESS_ROLES, true)) {
            $items[] = ['key' => 'documents', 'label' => 'დოკუმენტები', 'href' => route('documents.index'), 'icon' => 'documents'];
        }

        if (in_array($activeRole, self::CMS_ACCESS_ROLES, true)) {
            $items[] = ['key' => 'cms', 'label' => 'CMS', 'href' => route('cms.pages.index'), 'icon' => 'cms'];
        }

        if (in_array($activeRole, self::MEMBER_ACCESS_ROLES, true)) {
            $items[] = ['key' => 'members', 'label' => 'წევრები', 'href' => route('members.index'), 'icon' => 'members'];
        }

        if (in_array($activeRole, self::ACADEMIC_STRUCTURE_ACCESS_ROLES, true)) {
            $items[] = ['key' => 'academic-structure', 'label' => 'სასწავლო წლები', 'href' => route('academic-structure.index'), 'icon' => 'academic-structure'];
        }

        if (in_array($activeRole, self::TEACHER_MANAGEMENT_ACCESS_ROLES, true)) {
            $items[] = ['key' => 'teachers', 'label' => 'მასწავლებლები', 'href' => route('teachers.index'), 'icon' => 'teachers'];
        }

        if (in_array($activeRole, self::ASSIGNMENT_ACCESS_ROLES, true)) {
            $items[] = [
                'key' => 'assignments',
                'label' => 'დავალებები',
                'href' => $activeRole === TenantMembership::ROLE_TEACHER
                    ? route('assignments.index')
                    : route('my-assignments.index'),
                'icon' => 'assignments',
            ];
        }

        if (in_array($activeRole, self::TIMETABLE_ACCESS_ROLES, true)) {
            $items[] = ['key' => 'timetable', 'label' => 'განრიგი', 'href' => route('timetable.index'), 'icon' => 'timetable'];
        }

        if (in_array($activeRole, self::SUBSTITUTION_ACCESS_ROLES, true)) {
            $items[] = ['key' => 'substitutions', 'label' => 'ჩანაცვლებები', 'href' => route('substitutions.index'), 'icon' => 'substitutions'];
        }

        if (in_array($activeRole, self::ADMISSIONS_PIPELINE_ACCESS_ROLES, true)) {
            $items[] = ['key' => 'admissions-pipeline', 'label' => 'მიღების პროცესი', 'href' => route('admissions-pipeline.index'), 'icon' => 'admissions-pipeline'];
        }

        if (in_array($activeRole, self::PICKUP_ACCESS_ROLES, true)) {
            $items[] = ['key' => 'pickup', 'label' => 'უფლებამოსილი პირები', 'href' => route('pickup.index'), 'icon' => 'pickup'];
        }

        if (in_array($activeRole, self::CONSENT_ACCESS_ROLES, true)) {
            $items[] = ['key' => 'consents', 'label' => 'თანხმობები', 'href' => route('consents.index'), 'icon' => 'consents'];
        }

        return $items;
    }

    /**
     * @return array{activeRole: string|null, availableRoles: array<int, array{value: string, label: string}>, navItems: array<int, array{key: string, label: string, href: string, icon: string}>}
     */
    public function propsFor(Request $request, Tenant $tenant, User $user): array
    {
        $activeRole = $this->resolveActiveRole($request, $tenant->id, $user->id);
        $available = $this->activeRoles($tenant->id, $user->id);

        return [
            'activeRole' => $activeRole,
            'availableRoles' => array_map(fn (string $role) => ['value' => $role, 'label' => self::ROLE_LABELS[$role] ?? $role], $available),
            'navItems' => $activeRole !== null ? $this->navItemsFor($activeRole) : [],
        ];
    }
}
