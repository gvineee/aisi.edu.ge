<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\Student;
use App\Domain\Academics\Models\TeacherAssignment;
use App\Domain\Tenancy\Actions\CreateTenantInvitation;
use App\Domain\Tenancy\Actions\DecideEnrollmentVerificationRequest as DecideEnrollmentVerificationRequestAction;
use App\Domain\Tenancy\Actions\RevokeTenantMembership;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\EnrollmentVerificationRequest;
use App\Domain\Tenancy\Models\TenantInvitation;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\DecideEnrollmentVerificationRequest;
use App\Http\Requests\Portal\StoreTenantInvitationRequest;
use App\Mail\TenantInvitationMail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin/director-only "who has access" screen (there was previously no
 * way to invite anyone — teachers, guardians, students, staff — into the
 * portal at all; every non-seeded account had to be created by hand).
 * Every action here re-checks TenantMembership itself; ADMIN_ROLES is a UI
 * convenience only, never the authorization boundary on its own.
 */
class MemberController extends Controller
{
    /**
     * @var array<int, string>
     */
    private const ADMIN_ROLES = [TenantMembership::ROLE_ADMIN, TenantMembership::ROLE_DIRECTOR];

    public function index(Request $request, CurrentTenant $currentTenant): Response
    {
        $tenant = $currentTenant->get();
        $user = $request->user();

        abort_unless(TenantMembership::userHasAnyActiveRole($tenant->id, $user->id, self::ADMIN_ROLES), 403);

        $memberships = TenantMembership::query()
            ->where('tenant_id', $tenant->id)
            ->with('user')
            ->orderByDesc('is_active')
            ->orderBy('role')
            ->get();

        $guardianLinksByUser = GuardianLink::query()
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->with('student')
            ->get()
            ->groupBy('user_id');

        $teacherAssignmentsByUser = TeacherAssignment::query()
            ->where('tenant_id', $tenant->id)
            ->with('schoolClass')
            ->get()
            ->groupBy('user_id');

        $invitations = TenantInvitation::query()
            ->where('tenant_id', $tenant->id)
            ->whereNull('accepted_at')
            ->where('expires_at', '>=', now())
            ->with(['student', 'schoolClass'])
            ->latest('created_at')
            ->get();

        $enrollmentRequests = EnrollmentVerificationRequest::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', EnrollmentVerificationRequest::STATUS_PENDING)
            ->with(['user', 'matchedStudent.schoolClass'])
            ->latest('created_at')
            ->get();

        return Inertia::render('portal/members/index', [
            'members' => $memberships->map(fn (TenantMembership $membership) => [
                'id' => $membership->id,
                'userName' => $membership->user->name,
                'userEmail' => $membership->user->email,
                'role' => $membership->role,
                'isActive' => $membership->is_active,
                'linkedStudents' => $guardianLinksByUser->get($membership->user_id, collect())
                    ->map(fn (GuardianLink $link) => $link->student?->fullName())
                    ->filter()
                    ->values(),
                'linkedClasses' => $teacherAssignmentsByUser->get($membership->user_id, collect())
                    ->map(fn (TeacherAssignment $assignment) => trim($assignment->schoolClass->name.($assignment->subject ? " · {$assignment->subject}" : '')))
                    ->filter()
                    ->values(),
            ])->values(),
            'pendingInvitations' => $invitations->map(fn (TenantInvitation $invitation) => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $invitation->role,
                'studentName' => $invitation->student?->fullName(),
                'className' => $invitation->schoolClass?->name,
                'expiresAt' => $invitation->expires_at->toIso8601String(),
            ])->values(),
            'students' => Student::query()->where('tenant_id', $tenant->id)->where('is_active', true)
                ->orderBy('first_name')->get(['id', 'first_name', 'last_name'])
                ->map(fn (Student $student) => ['id' => $student->id, 'name' => $student->fullName()])->values(),
            'schoolClasses' => SchoolClass::query()->where('tenant_id', $tenant->id)
                ->orderBy('name')->get(['id', 'name']),
            'enrollmentRequests' => $enrollmentRequests->map(fn (EnrollmentVerificationRequest $request) => [
                'id' => $request->id,
                'userName' => $request->user->name,
                'userEmail' => $request->user->email,
                'requestedRole' => $request->requested_role,
                'submittedName' => $request->submittedFullName(),
                'submittedNationalId' => $request->submitted_national_id,
                'matchedStudent' => $request->matchedStudent === null ? null : [
                    'id' => $request->matchedStudent->id,
                    'name' => $request->matchedStudent->fullName(),
                    'className' => $request->matchedStudent->schoolClass?->name,
                ],
                'createdAt' => $request->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function store(StoreTenantInvitationRequest $request, CurrentTenant $currentTenant, CreateTenantInvitation $action): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $actor = $request->user();

        abort_unless(TenantMembership::userHasAnyActiveRole($tenant->id, $actor->id, self::ADMIN_ROLES), 403);

        $role = $request->string('role')->toString();
        $studentId = $request->integer('student_id') ?: null;
        $schoolClassId = $request->integer('school_class_id') ?: null;

        if ($role === TenantMembership::ROLE_GUARDIAN && $studentId !== null) {
            abort_unless(Student::query()->where('tenant_id', $tenant->id)->whereKey($studentId)->exists(), 404);
        }

        if ($role === TenantMembership::ROLE_TEACHER && $schoolClassId !== null) {
            abort_unless(SchoolClass::query()->where('tenant_id', $tenant->id)->whereKey($schoolClassId)->exists(), 404);
        }

        $invitation = $action->handle(
            tenantId: $tenant->id,
            invitedBy: $actor,
            email: $request->string('email')->toString(),
            role: $role,
            studentId: $role === TenantMembership::ROLE_GUARDIAN ? $studentId : null,
            schoolClassId: $role === TenantMembership::ROLE_TEACHER ? $schoolClassId : null,
            subject: $role === TenantMembership::ROLE_TEACHER ? ($request->string('subject')->toString() ?: null) : null,
            guardianPermissions: [
                'can_view_academic' => $request->boolean('can_view_academic', true),
                'can_view_financial' => $request->boolean('can_view_financial'),
                'can_pickup' => $request->boolean('can_pickup'),
                'can_receive_notifications' => $request->boolean('can_receive_notifications', true),
            ],
        );

        Mail::to($invitation->email)->send(new TenantInvitationMail(
            invitation: $invitation,
            tenantName: $tenant->name,
            acceptUrl: route('invitations.show', $invitation->token),
            roleLabel: self::roleLabel($role),
        ));

        return redirect()->route('members.index')->with('toast', [
            'type' => 'success',
            'message' => 'მოწვევა გაიგზავნა.',
        ]);
    }

    public function revoke(Request $request, CurrentTenant $currentTenant, TenantMembership $membership, RevokeTenantMembership $action): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $actor = $request->user();

        abort_unless(TenantMembership::userHasAnyActiveRole($tenant->id, $actor->id, self::ADMIN_ROLES), 403);
        abort_unless($membership->tenant_id === $tenant->id, 404);

        $action->handle($membership, $actor);

        return redirect()->route('members.index')->with('toast', [
            'type' => 'success',
            'message' => 'წვდომა გაუქმდა.',
        ]);
    }

    public function cancelInvitation(Request $request, CurrentTenant $currentTenant, TenantInvitation $invitation): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $actor = $request->user();

        abort_unless(TenantMembership::userHasAnyActiveRole($tenant->id, $actor->id, self::ADMIN_ROLES), 403);
        abort_unless($invitation->tenant_id === $tenant->id, 404);
        abort_if($invitation->isAccepted(), 422, 'მიღებული მოწვევის გაუქმება შეუძლებელია.');

        $invitation->delete();

        return redirect()->route('members.index');
    }

    public function decideEnrollment(
        DecideEnrollmentVerificationRequest $request,
        CurrentTenant $currentTenant,
        EnrollmentVerificationRequest $enrollmentRequest,
        DecideEnrollmentVerificationRequestAction $action,
    ): RedirectResponse {
        $tenant = $currentTenant->get();
        $actor = $request->user();

        abort_unless(TenantMembership::userHasAnyActiveRole($tenant->id, $actor->id, self::ADMIN_ROLES), 403);
        abort_unless($enrollmentRequest->tenant_id === $tenant->id, 404);

        if ($request->string('decision')->toString() === 'approve') {
            $action->approve($enrollmentRequest, $actor, $request->integer('student_id') ?: null);
            $message = 'მოთხოვნა დამტკიცდა.';
        } else {
            $action->reject($enrollmentRequest, $actor, $request->string('reason')->toString());
            $message = 'მოთხოვნა უარყოფილია.';
        }

        return redirect()->route('members.index')->with('toast', [
            'type' => 'success',
            'message' => $message,
        ]);
    }

    private static function roleLabel(string $role): string
    {
        return match ($role) {
            TenantMembership::ROLE_STUDENT => 'მოსწავლე',
            TenantMembership::ROLE_GUARDIAN => 'მშობელი',
            TenantMembership::ROLE_TEACHER => 'მასწავლებელი',
            TenantMembership::ROLE_ACADEMIC_MANAGER => 'აკადემიური მენეჯერი',
            TenantMembership::ROLE_ACCOUNTANT => 'ბუღალტერი',
            TenantMembership::ROLE_EDITOR => 'რედაქტორი',
            TenantMembership::ROLE_ADMIN => 'ადმინისტრატორი',
            TenantMembership::ROLE_DIRECTOR => 'დირექტორი',
            default => $role,
        };
    }
}
