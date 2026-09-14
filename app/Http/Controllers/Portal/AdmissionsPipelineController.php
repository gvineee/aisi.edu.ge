<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Admissions\Actions\AdvanceLeadStage;
use App\Domain\Admissions\Actions\RecordDecision;
use App\Domain\Admissions\Actions\ScheduleAppointment;
use App\Domain\Admissions\Models\AdmissionAppointment;
use App\Domain\Admissions\Models\AdmissionDecision;
use App\Domain\Admissions\Models\AdmissionLead;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\AdvanceAdmissionLeadStageRequest;
use App\Http\Requests\Portal\RecordAdmissionDecisionRequest;
use App\Http\Requests\Portal\ScheduleAdmissionAppointmentRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "მიღების პროცესი" — turns the Phase 1 public lead form
 * (AdmissionLeadController, docs/02 §5.1) into a real pipeline: the last
 * audit found Admissions was 0% built beyond that bare contact form.
 * Admin/director/academic_manager triage leads through
 * AdmissionLead::STAGES, schedule visits, and record the final decision.
 * Nav visibility is not the authorization boundary — every action below
 * re-checks the role itself, same pattern as SubstitutionController.
 */
class AdmissionsPipelineController extends Controller
{
    /**
     * @var array<int, string>
     */
    private const ACCESS_ROLES = [
        TenantMembership::ROLE_ACADEMIC_MANAGER,
        TenantMembership::ROLE_DIRECTOR,
        TenantMembership::ROLE_ADMIN,
    ];

    /**
     * @var array<string, string>
     */
    private const STAGE_LABELS = [
        AdmissionLead::STAGE_NEW => 'ახალი',
        AdmissionLead::STAGE_CONTACTED => 'დაკავშირებული',
        AdmissionLead::STAGE_VISIT_SCHEDULED => 'ვიზიტი დაგეგმილია',
        AdmissionLead::STAGE_DOCUMENTS_SUBMITTED => 'დოკუმენტები წარდგენილია',
        AdmissionLead::STAGE_DECIDED => 'გადაწყვეტილია',
    ];

    public function index(Request $request, CurrentTenant $currentTenant): Response
    {
        $tenant = $currentTenant->get();
        $this->authorizeAccess($tenant->id, $request->user()->id);

        $stageFilter = $request->string('stage')->toString();
        $stageFilter = in_array($stageFilter, AdmissionLead::STAGES, true) ? $stageFilter : '';

        $leads = AdmissionLead::query()
            ->where('tenant_id', $tenant->id)
            ->when($stageFilter !== '', fn ($query) => $query->where('stage', $stageFilter))
            ->withCount('appointments')
            ->latest('created_at')
            ->get();

        return Inertia::render('portal/admissions-pipeline/index', [
            'leads' => $leads->map(fn (AdmissionLead $lead) => $this->formatSummary($lead))->values(),
            'stages' => $this->stageOptions(),
            'filters' => ['stage' => $stageFilter],
        ]);
    }

    public function show(Request $request, CurrentTenant $currentTenant, AdmissionLead $admissionLead): Response
    {
        $tenant = $currentTenant->get();
        $this->authorizeAccess($tenant->id, $request->user()->id);
        abort_unless($admissionLead->tenant_id === $tenant->id, 404);

        $admissionLead->load(['appointments.creator', 'decision.decider']);

        return Inertia::render('portal/admissions-pipeline/show', [
            'lead' => $this->formatDetail($admissionLead),
            'stages' => $this->stageOptions(),
            'nextStages' => $this->legalNextStages($admissionLead),
            'decisionOptions' => array_map(
                fn (string $value) => ['value' => $value, 'label' => $this->decisionLabel($value)],
                AdmissionDecision::DECISIONS,
            ),
        ]);
    }

    public function advanceStage(AdvanceAdmissionLeadStageRequest $request, CurrentTenant $currentTenant, AdmissionLead $admissionLead, AdvanceLeadStage $action): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $actor = $request->user();
        $this->authorizeAccess($tenant->id, $actor->id);
        abort_unless($admissionLead->tenant_id === $tenant->id, 404);

        $action->handle($admissionLead, $actor, $request->string('stage')->toString());

        return redirect()->route('admissions-pipeline.show', $admissionLead)->with('toast', [
            'type' => 'success', 'message' => 'ეტაპი განახლდა.',
        ]);
    }

    public function storeAppointment(ScheduleAdmissionAppointmentRequest $request, CurrentTenant $currentTenant, AdmissionLead $admissionLead, ScheduleAppointment $action): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $actor = $request->user();
        $this->authorizeAccess($tenant->id, $actor->id);
        abort_unless($admissionLead->tenant_id === $tenant->id, 404);

        $action->handle(
            lead: $admissionLead,
            actor: $actor,
            scheduledAt: Carbon::parse((string) $request->string('scheduled_at')),
            notes: $request->string('notes')->toString() ?: null,
        );

        return redirect()->route('admissions-pipeline.show', $admissionLead)->with('toast', [
            'type' => 'success', 'message' => 'ვიზიტი დაინიშნა.',
        ]);
    }

    public function storeDecision(RecordAdmissionDecisionRequest $request, CurrentTenant $currentTenant, AdmissionLead $admissionLead, RecordDecision $action): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $actor = $request->user();
        $this->authorizeAccess($tenant->id, $actor->id);
        abort_unless($admissionLead->tenant_id === $tenant->id, 404);

        $action->handle(
            lead: $admissionLead,
            actor: $actor,
            decision: $request->string('decision')->toString(),
            notes: $request->string('notes')->toString() ?: null,
        );

        return redirect()->route('admissions-pipeline.show', $admissionLead)->with('toast', [
            'type' => 'success', 'message' => 'გადაწყვეტილება დაფიქსირდა.',
        ]);
    }

    private function authorizeAccess(int $tenantId, int $userId): void
    {
        abort_unless(TenantMembership::userHasAnyActiveRole($tenantId, $userId, self::ACCESS_ROLES), 403);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function stageOptions(): array
    {
        return array_map(
            fn (string $stage) => ['value' => $stage, 'label' => self::STAGE_LABELS[$stage]],
            AdmissionLead::STAGES,
        );
    }

    /**
     * Stages this lead could legally be moved to next via advanceStage —
     * strictly forward, excluding the decision-only terminal stage. Mirrors
     * AdvanceLeadStage's own rule so the UI never offers an illegal option.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function legalNextStages(AdmissionLead $lead): array
    {
        $currentIndex = $lead->stageIndex();

        if ($currentIndex === null) {
            return [];
        }

        return array_values(array_map(
            fn (string $stage) => ['value' => $stage, 'label' => self::STAGE_LABELS[$stage]],
            array_filter(
                AdmissionLead::STAGES,
                fn (string $stage) => $stage !== AdmissionLead::STAGE_DECIDED
                    && array_search($stage, AdmissionLead::STAGES, true) > $currentIndex,
            ),
        ));
    }

    private function decisionLabel(string $decision): string
    {
        return match ($decision) {
            AdmissionDecision::DECISION_ACCEPTED => 'მიღებულია',
            AdmissionDecision::DECISION_DECLINED => 'უარყოფილია',
            AdmissionDecision::DECISION_WAITLISTED => 'ლისტში',
            default => $decision,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function formatSummary(AdmissionLead $lead): array
    {
        return [
            'id' => $lead->id,
            'guardianName' => $lead->guardian_name,
            'contactMethod' => $lead->contact_method,
            'contactValue' => $lead->contact_value,
            'desiredGrade' => $lead->desired_grade,
            'stage' => $lead->stage,
            'appointmentsCount' => (int) ($lead->appointments_count ?? 0),
            'createdAt' => $lead->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatDetail(AdmissionLead $lead): array
    {
        return [
            'id' => $lead->id,
            'guardianName' => $lead->guardian_name,
            'contactMethod' => $lead->contact_method,
            'contactValue' => $lead->contact_value,
            'desiredGrade' => $lead->desired_grade,
            'preferredDate' => $lead->preferred_date,
            'stage' => $lead->stage,
            'createdAt' => $lead->created_at?->toIso8601String(),
            'appointments' => $lead->appointments->map(fn (AdmissionAppointment $appointment) => [
                'id' => $appointment->id,
                'scheduledAt' => $appointment->scheduled_at->toIso8601String(),
                'notes' => $appointment->notes,
                'createdByName' => $appointment->creator?->name,
            ])->values(),
            'decision' => $lead->decision === null ? null : [
                'id' => $lead->decision->id,
                'decision' => $lead->decision->decision,
                'decidedAt' => $lead->decision->decided_at->toIso8601String(),
                'notes' => $lead->decision->notes,
                'decidedByName' => $lead->decision->decider?->name,
            ],
        ];
    }
}
