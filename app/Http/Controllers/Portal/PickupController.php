<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Academics\Models\Student;
use App\Domain\PickupConsent\Actions\AddAuthorizedPickup;
use App\Domain\PickupConsent\Actions\PickupCodes;
use App\Domain\PickupConsent\Actions\RemoveAuthorizedPickup;
use App\Domain\PickupConsent\Models\AuthorizedPickup;
use App\Domain\PickupConsent\Models\PickupCode;
use App\Domain\PickupConsent\Models\PickupEvent;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\StoreAuthorizedPickupRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "უფლებამოსილი პირები" — guardian-facing management of who else may pick up
 * their child (CLAUDE-PLATFORM-MODULES.md §7). Every action here is scoped
 * to the caller's own active {@see GuardianLink} rows that additionally carry
 * the dedicated `can_pickup` permission bit — an active link alone grants
 * academic/notification visibility, not authority over physical pickup
 * (CLAUDE.md invariant #3: guardian access needs an active link AND the
 * specific permission). A client-supplied student id is never trusted on its
 * own — see activeGuardianLink().
 */
class PickupController extends Controller
{
    /**
     * Staff who may verify a pickup code at the door.
     *
     * @var array<int, string>
     */
    private const VERIFIER_ROLES = [
        TenantMembership::ROLE_TEACHER,
        TenantMembership::ROLE_ACADEMIC_MANAGER,
        TenantMembership::ROLE_DIRECTOR,
        TenantMembership::ROLE_ADMIN,
    ];

    /**
     * Senior staff who may release a child without a code (reason required).
     *
     * @var array<int, string>
     */
    private const OVERRIDE_ROLES = [
        TenantMembership::ROLE_ACADEMIC_MANAGER,
        TenantMembership::ROLE_DIRECTOR,
        TenantMembership::ROLE_ADMIN,
    ];

    public function index(Request $request, CurrentTenant $currentTenant): Response
    {
        $tenant = $currentTenant->get();
        $user = $request->user();

        $links = GuardianLink::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->where('can_pickup', true)
            ->with(['student' => fn ($query) => $query->where('is_active', true)])
            ->get()
            ->filter(fn (GuardianLink $link) => $link->student !== null);

        $children = $links->map(fn (GuardianLink $link) => $this->formatChild($tenant->id, $link))->values();

        return Inertia::render('portal/pickup/index', [
            'children' => $children,
            // Shown exactly once, right after issuing; never stored in plain text.
            'issuedCode' => $request->session()->get('issuedPickupCode'),
        ]);
    }

    public function store(StoreAuthorizedPickupRequest $request, CurrentTenant $currentTenant, AddAuthorizedPickup $addAuthorizedPickup): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $user = $request->user();
        $studentId = $request->integer('student_id');

        $link = $this->activeGuardianLink($tenant->id, $studentId, $user->id);
        abort_unless($link !== null, 403);

        $addAuthorizedPickup->handle(
            student: $link->student,
            guardian: $user,
            fullName: $request->string('full_name')->toString(),
            relationship: $request->string('relationship')->toString(),
            idDocumentNumber: $request->string('id_document_number')->toString() ?: null,
        );

        return redirect()->route('pickup.index');
    }

    public function destroy(Request $request, CurrentTenant $currentTenant, AuthorizedPickup $authorizedPickup, RemoveAuthorizedPickup $removeAuthorizedPickup): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($authorizedPickup->tenant_id === $tenant->id, 404);

        $user = $request->user();
        $link = $this->activeGuardianLink($tenant->id, $authorizedPickup->student_id, $user->id);
        abort_unless($link !== null, 403);

        $removeAuthorizedPickup->handle($authorizedPickup, $user);

        return redirect()->route('pickup.index');
    }

    public function issueCode(Request $request, CurrentTenant $currentTenant, PickupCodes $pickupCodes): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $user = $request->user();
        $request->validate([
            'student_id' => ['required', 'integer'],
            'authorized_pickup_id' => ['nullable', 'integer'],
        ]);

        $link = $this->activeGuardianLink($tenant->id, $request->integer('student_id'), $user->id);
        abort_unless($link !== null, 403);

        $person = $request->filled('authorized_pickup_id')
            ? AuthorizedPickup::query()->where('tenant_id', $tenant->id)->whereKey($request->integer('authorized_pickup_id'))->firstOrFail()
            : null;

        $issued = $pickupCodes->issue($link->student, $user, $person);

        return redirect()->route('pickup.index')->with('issuedPickupCode', [
            'code' => $issued['code'],
            'studentName' => $link->student->fullName(),
            'personName' => $person !== null ? $person->full_name : $user->name,
            'expiresAt' => $issued['model']->expires_at->toIso8601String(),
        ]);
    }

    public function cancelCode(Request $request, CurrentTenant $currentTenant, PickupCode $pickupCode, PickupCodes $pickupCodes): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($pickupCode->tenant_id === $tenant->id, 404);
        abort_unless($this->activeGuardianLink($tenant->id, $pickupCode->student_id, $request->user()->id) !== null, 403);

        $pickupCodes->cancel($pickupCode, $request->user());

        return redirect()->route('pickup.index');
    }

    /**
     * Staff-side verification desk: enter the code shown by whoever is
     * collecting the child; see only what is needed to release them.
     */
    public function verifyDesk(Request $request, CurrentTenant $currentTenant): Response
    {
        $tenant = $currentTenant->get();
        $user = $request->user();
        abort_unless(TenantMembership::userHasAnyActiveRole($tenant->id, $user->id, self::VERIFIER_ROLES), 403);

        $canOverride = TenantMembership::userHasAnyActiveRole($tenant->id, $user->id, self::OVERRIDE_ROLES);

        return Inertia::render('portal/pickup/verify', [
            'result' => $request->session()->get('pickupResult'),
            'canOverride' => $canOverride,
            'students' => $canOverride
                ? Student::query()->where('tenant_id', $tenant->id)->where('is_active', true)->with('schoolClass')->orderBy('last_name')->orderBy('first_name')->get()
                    ->map(fn (Student $student) => ['id' => $student->id, 'name' => $student->fullName(), 'className' => $student->schoolClass?->name])->values()
                : [],
            'todayEvents' => PickupEvent::query()
                ->where('tenant_id', $tenant->id)
                ->where('created_at', '>=', Carbon::now('Asia/Tbilisi')->startOfDay()->utc())
                ->latest('id')
                ->limit(50)
                ->get()
                ->map(fn (PickupEvent $event) => [
                    'id' => $event->id,
                    'method' => $event->method,
                    'releasedTo' => $event->released_to,
                    'createdAt' => $event->created_at?->toIso8601String(),
                ])->values(),
        ]);
    }

    public function verify(Request $request, CurrentTenant $currentTenant, PickupCodes $pickupCodes): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $user = $request->user();
        abort_unless(TenantMembership::userHasAnyActiveRole($tenant->id, $user->id, self::VERIFIER_ROLES), 403);
        $request->validate(['code' => ['required', 'string', 'max:20']]);

        $result = $pickupCodes->consume($tenant->id, $request->string('code')->toString(), $user);

        return redirect()->route('pickup.verify-desk')->with('pickupResult', $result);
    }

    public function override(Request $request, CurrentTenant $currentTenant, PickupCodes $pickupCodes): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $user = $request->user();
        abort_unless(TenantMembership::userHasAnyActiveRole($tenant->id, $user->id, self::OVERRIDE_ROLES), 403);
        $request->validate([
            'student_id' => ['required', 'integer'],
            'released_to' => ['required', 'string', 'max:255'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $student = Student::query()->where('tenant_id', $tenant->id)->whereKey($request->integer('student_id'))->firstOrFail();
        $pickupCodes->override($student, $user, $request->string('released_to')->toString(), $request->string('reason')->toString());

        return redirect()->route('pickup.verify-desk');
    }

    private function activeGuardianLink(int $tenantId, int $studentId, int $userId): ?GuardianLink
    {
        return GuardianLink::query()
            ->where('tenant_id', $tenantId)
            ->where('student_id', $studentId)
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->where('can_pickup', true)
            ->with('student')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function formatChild(int $tenantId, GuardianLink $link): array
    {
        $pickups = AuthorizedPickup::query()
            ->where('tenant_id', $tenantId)
            ->where('student_id', $link->student_id)
            ->where('is_active', true)
            ->latest('created_at')
            ->get();

        $codes = PickupCode::query()
            ->where('tenant_id', $tenantId)
            ->where('student_id', $link->student_id)
            ->whereNull('consumed_at')
            ->whereNull('cancelled_at')
            ->where('expires_at', '>', Carbon::now())
            ->with('authorizedPickup')
            ->latest('id')
            ->get();

        return [
            'id' => $link->student->id,
            'name' => $link->student->fullName(),
            'activeCodes' => $codes->map(fn (PickupCode $code) => [
                'id' => $code->id,
                'personName' => $code->authorizedPickup?->full_name,
                'expiresAt' => $code->expires_at->toIso8601String(),
            ])->values(),
            'pickups' => $pickups->map(fn (AuthorizedPickup $pickup) => [
                'id' => $pickup->id,
                'fullName' => $pickup->full_name,
                'relationship' => $pickup->relationship,
                'idDocumentNumber' => $pickup->id_document_number,
            ])->values(),
        ];
    }
}
