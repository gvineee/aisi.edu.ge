<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Academics\Models\Student;
use App\Domain\PickupConsent\Actions\PublishConsentForm;
use App\Domain\PickupConsent\Actions\RespondToConsent;
use App\Domain\PickupConsent\Models\ConsentForm;
use App\Domain\PickupConsent\Models\ConsentResponse;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\PublishConsentFormRequest;
use App\Http\Requests\Portal\RespondToConsentRequest;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "თანხმობები" (CLAUDE-PLATFORM-MODULES.md §7). Admin/director publish
 * school-wide consent forms here; guardians respond on behalf of their own
 * active children. The same `consents.index` route renders a different
 * screen per role, mirroring DashboardController's per-role branching —
 * nav visibility is not the authorization boundary, every action below
 * re-checks the role/guardian link itself.
 */
class ConsentController extends Controller
{
    private const STAFF_ROLES = [
        TenantMembership::ROLE_ADMIN,
        TenantMembership::ROLE_DIRECTOR,
    ];

    public function index(Request $request, CurrentTenant $currentTenant): Response
    {
        $tenant = $currentTenant->get();
        $user = $request->user();

        if (TenantMembership::userHasAnyActiveRole($tenant->id, $user->id, self::STAFF_ROLES)) {
            return $this->renderStaffIndex($tenant->id);
        }

        return $this->renderGuardianIndex($tenant->id, $user->id);
    }

    public function store(PublishConsentFormRequest $request, CurrentTenant $currentTenant, PublishConsentForm $publishConsentForm): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $user = $request->user();
        abort_unless(TenantMembership::userHasAnyActiveRole($tenant->id, $user->id, self::STAFF_ROLES), 403);

        $publishConsentForm->handle(
            tenantId: $tenant->id,
            publisher: $user,
            title: $request->string('title')->toString(),
            body: $request->string('body')->toString(),
            requiresSignature: $request->boolean('requires_signature', true),
        );

        return redirect()->route('consents.index');
    }

    public function roster(Request $request, CurrentTenant $currentTenant, ConsentForm $consentForm): Response
    {
        $tenant = $currentTenant->get();
        abort_unless($consentForm->tenant_id === $tenant->id, 404);

        $user = $request->user();
        abort_unless(TenantMembership::userHasAnyActiveRole($tenant->id, $user->id, self::STAFF_ROLES), 403);

        $students = Student::query()
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        $responsesByStudent = ConsentResponse::query()
            ->where('consent_form_id', $consentForm->id)
            ->get()
            ->keyBy('student_id');

        return Inertia::render('portal/consents/roster', [
            'form' => [
                'id' => $consentForm->id,
                'title' => $consentForm->title,
            ],
            'rows' => $students->map(function (Student $student) use ($responsesByStudent) {
                $response = $responsesByStudent->get($student->id);

                return [
                    'studentId' => $student->id,
                    'studentName' => $student->fullName(),
                    'status' => $response === null ? 'pending' : ($response->granted ? 'granted' : 'denied'),
                    'respondedAt' => $response?->responded_at?->toIso8601String(),
                ];
            })->values(),
        ]);
    }

    public function respond(RespondToConsentRequest $request, CurrentTenant $currentTenant, ConsentForm $consentForm, RespondToConsent $respondToConsent): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($consentForm->tenant_id === $tenant->id, 404);

        $user = $request->user();
        $studentId = $request->integer('student_id');

        $link = GuardianLink::query()
            ->where('tenant_id', $tenant->id)
            ->where('student_id', $studentId)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->with('student')
            ->first();

        abort_unless($link !== null, 403);

        $respondToConsent->handle(
            form: $consentForm,
            student: $link->student,
            guardian: $user,
            granted: $request->boolean('granted'),
        );

        return redirect()->route('consents.index');
    }

    private function renderStaffIndex(int $tenantId): Response
    {
        $totalActiveStudents = Student::query()->where('tenant_id', $tenantId)->where('is_active', true)->count();

        $forms = ConsentForm::query()
            ->where('tenant_id', $tenantId)
            ->latest('created_at')
            ->get()
            ->map(function (ConsentForm $form) use ($totalActiveStudents) {
                $granted = ConsentResponse::query()->where('consent_form_id', $form->id)->where('granted', true)->count();
                $denied = ConsentResponse::query()->where('consent_form_id', $form->id)->where('granted', false)->count();

                return [
                    'id' => $form->id,
                    'title' => $form->title,
                    'requiresSignature' => $form->requires_signature,
                    'createdAt' => $form->created_at?->toIso8601String(),
                    'totalStudents' => $totalActiveStudents,
                    'granted' => $granted,
                    'denied' => $denied,
                    'pending' => max(0, $totalActiveStudents - $granted - $denied),
                ];
            })
            ->values();

        return Inertia::render('portal/consents/index', [
            'isStaffView' => true,
            'forms' => $forms,
        ]);
    }

    private function renderGuardianIndex(int $tenantId, int $userId): Response
    {
        $links = GuardianLink::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->with(['student' => fn ($query) => $query->where('is_active', true)])
            ->get()
            ->filter(fn (GuardianLink $link) => $link->student !== null);

        $forms = ConsentForm::query()->where('tenant_id', $tenantId)->latest('created_at')->get();

        $children = $links->map(fn (GuardianLink $link) => $this->formatChild($link, $forms))->values();

        return Inertia::render('portal/consents/guardian-index', [
            'isStaffView' => false,
            'children' => $children,
        ]);
    }

    /**
     * @param  Collection<int, ConsentForm>  $forms
     * @return array<string, mixed>
     */
    private function formatChild(GuardianLink $link, Collection $forms): array
    {
        $responses = ConsentResponse::query()
            ->where('student_id', $link->student_id)
            ->get()
            ->keyBy('consent_form_id');

        return [
            'id' => $link->student->id,
            'name' => $link->student->fullName(),
            'forms' => $forms->map(fn (ConsentForm $form) => $this->formatFormStatus($form, $responses->get($form->id)))->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatFormStatus(ConsentForm $form, ?ConsentResponse $response): array
    {
        return [
            'id' => $form->id,
            'title' => $form->title,
            'body' => $form->body,
            'requiresSignature' => $form->requires_signature,
            'granted' => $response?->granted,
            'respondedAt' => $response?->responded_at?->toIso8601String(),
        ];
    }
}
