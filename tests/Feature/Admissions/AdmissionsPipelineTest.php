<?php

namespace Tests\Feature\Admissions;

use App\Domain\Admissions\Models\AdmissionDecision;
use App\Domain\Admissions\Models\AdmissionLead;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the Admissions pipeline — the piece the last audit found 0% built
 * beyond the Phase 1 public lead form (AdmissionLeadController). Tenant
 * isolation (CLAUDE.md invariants #1-2), role gating (#3-4) and the
 * forward-only stage-transition rule (AdvanceLeadStage) are covered
 * directly, plus the full happy path from public lead capture through to a
 * recorded decision.
 */
class AdmissionsPipelineTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{tenant: Tenant, admin: User, teacher: User}
     */
    private function baseFixtures(): array
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;

        $admin = User::factory()->create();
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $admin->id, 'role' => TenantMembership::ROLE_ADMIN, 'is_active' => true]);

        $teacher = User::factory()->create();
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $teacher->id, 'role' => TenantMembership::ROLE_TEACHER, 'is_active' => true]);

        return compact('tenant', 'admin', 'teacher');
    }

    private function createLead(Tenant $tenant, string $stage = AdmissionLead::STAGE_NEW): AdmissionLead
    {
        return $tenant->admissionLeads()->create([
            'guardian_name' => 'მარიამ კ.',
            'contact_method' => 'phone',
            'contact_value' => '+995500000010',
            'desired_grade' => 'VI კლასი',
            'consent_given' => true,
            'stage' => $stage,
        ]);
    }

    public function test_a_public_lead_can_be_advanced_through_the_full_pipeline_to_a_decision(): void
    {
        $f = $this->baseFixtures();

        // The lead starts life exactly as the existing public form creates
        // it (AdmissionLeadController) — not seeded directly as "already in
        // the pipeline".
        $this->post(route('admissions.leads.store'), [
            'guardian_name' => 'ნინო ჯ.',
            'contact_method' => 'email',
            'contact_value' => 'nino@example.test',
            'desired_grade' => 'III კლასი',
            'consent_given' => true,
        ])->assertSessionHasNoErrors();

        $lead = AdmissionLead::query()->where('tenant_id', $f['tenant']->id)->firstOrFail();
        $this->assertSame(AdmissionLead::STAGE_NEW, $lead->stage);

        $this->actingAs($f['admin'])
            ->get(route('admissions-pipeline.index'))
            ->assertInertia(fn ($page) => $page
                ->component('portal/admissions-pipeline/index')
                ->where('leads.0.id', $lead->id)
            );

        $this->actingAs($f['admin'])
            ->post("/portal/admissions-pipeline/{$lead->id}/advance-stage", ['stage' => AdmissionLead::STAGE_CONTACTED])
            ->assertRedirect(route('admissions-pipeline.show', $lead));

        $this->assertSame(AdmissionLead::STAGE_CONTACTED, $lead->fresh()->stage);

        $this->actingAs($f['admin'])
            ->post("/portal/admissions-pipeline/{$lead->id}/advance-stage", ['stage' => AdmissionLead::STAGE_VISIT_SCHEDULED])
            ->assertRedirect(route('admissions-pipeline.show', $lead));

        $this->assertDatabaseHas('audit_events', [
            'tenant_id' => $f['tenant']->id,
            'action' => 'admission_lead.stage_advanced',
            'subject_id' => $lead->id,
        ]);

        $this->actingAs($f['admin'])
            ->post("/portal/admissions-pipeline/{$lead->id}/appointments", [
                'scheduled_at' => now()->addDays(3)->format('Y-m-d\TH:i'),
                'notes' => 'პირველადი გასაუბრება',
            ])
            ->assertRedirect(route('admissions-pipeline.show', $lead));

        $this->assertDatabaseCount('admission_appointments', 1);
        $this->assertDatabaseHas('admission_appointments', [
            'tenant_id' => $f['tenant']->id,
            'admission_lead_id' => $lead->id,
        ]);

        $this->actingAs($f['admin'])
            ->post("/portal/admissions-pipeline/{$lead->id}/advance-stage", ['stage' => AdmissionLead::STAGE_DOCUMENTS_SUBMITTED])
            ->assertRedirect(route('admissions-pipeline.show', $lead));

        $this->assertSame(AdmissionLead::STAGE_DOCUMENTS_SUBMITTED, $lead->fresh()->stage);

        $this->actingAs($f['admin'])
            ->get(route('admissions-pipeline.show', $lead))
            ->assertInertia(fn ($page) => $page
                ->component('portal/admissions-pipeline/show')
                ->has('lead.appointments', 1)
                ->where('lead.decision', null)
            );

        $this->actingAs($f['admin'])
            ->post("/portal/admissions-pipeline/{$lead->id}/decision", [
                'decision' => AdmissionDecision::DECISION_ACCEPTED,
                'notes' => 'ადგილი დადასტურებულია.',
            ])
            ->assertRedirect(route('admissions-pipeline.show', $lead));

        $lead->refresh();
        $this->assertSame(AdmissionLead::STAGE_DECIDED, $lead->stage);

        $this->assertDatabaseHas('admission_decisions', [
            'tenant_id' => $f['tenant']->id,
            'admission_lead_id' => $lead->id,
            'decision' => AdmissionDecision::DECISION_ACCEPTED,
            'decided_by' => $f['admin']->id,
        ]);

        $this->assertDatabaseHas('audit_events', [
            'tenant_id' => $f['tenant']->id,
            'action' => 'admission_lead.decided',
            'subject_id' => $lead->id,
        ]);

        // A lead can be decided exactly once.
        $this->actingAs($f['admin'])
            ->post("/portal/admissions-pipeline/{$lead->id}/decision", [
                'decision' => AdmissionDecision::DECISION_DECLINED,
            ])
            ->assertSessionHasErrors('decision');

        $this->assertDatabaseCount('admission_decisions', 1);
    }

    public function test_advancing_backward_or_skipping_directly_to_decided_is_rejected(): void
    {
        $f = $this->baseFixtures();
        $lead = $this->createLead($f['tenant'], AdmissionLead::STAGE_VISIT_SCHEDULED);

        // Backward.
        $this->actingAs($f['admin'])
            ->post("/portal/admissions-pipeline/{$lead->id}/advance-stage", ['stage' => AdmissionLead::STAGE_CONTACTED])
            ->assertSessionHasErrors('stage');

        // Same stage (not "forward").
        $this->actingAs($f['admin'])
            ->post("/portal/admissions-pipeline/{$lead->id}/advance-stage", ['stage' => AdmissionLead::STAGE_VISIT_SCHEDULED])
            ->assertSessionHasErrors('stage');

        // "decided" is reachable only through the decision endpoint.
        $this->actingAs($f['admin'])
            ->post("/portal/admissions-pipeline/{$lead->id}/advance-stage", ['stage' => AdmissionLead::STAGE_DECIDED])
            ->assertSessionHasErrors('stage');

        $this->assertSame(AdmissionLead::STAGE_VISIT_SCHEDULED, $lead->fresh()->stage);
    }

    public function test_a_teacher_cannot_view_or_act_on_the_admissions_pipeline(): void
    {
        $f = $this->baseFixtures();
        $lead = $this->createLead($f['tenant']);

        $this->actingAs($f['teacher'])->get(route('admissions-pipeline.index'))->assertForbidden();
        $this->actingAs($f['teacher'])->get(route('admissions-pipeline.show', $lead))->assertForbidden();

        $this->actingAs($f['teacher'])
            ->post("/portal/admissions-pipeline/{$lead->id}/advance-stage", ['stage' => AdmissionLead::STAGE_CONTACTED])
            ->assertForbidden();

        $this->actingAs($f['teacher'])
            ->post("/portal/admissions-pipeline/{$lead->id}/decision", ['decision' => AdmissionDecision::DECISION_ACCEPTED])
            ->assertForbidden();

        $this->assertSame(AdmissionLead::STAGE_NEW, $lead->fresh()->stage);
        $this->assertDatabaseCount('admission_decisions', 0);
    }

    public function test_a_tenants_admin_cannot_see_or_act_on_another_tenants_lead(): void
    {
        $f = $this->baseFixtures();

        $otherTenant = Tenant::create(['slug' => 'other-school-admissions', 'name' => 'Other', 'locale' => 'ka', 'timezone' => 'Asia/Tbilisi', 'is_active' => true]);
        $otherLead = $this->createLead($otherTenant);

        $this->actingAs($f['admin'])->get(route('admissions-pipeline.show', $otherLead))->assertNotFound();

        $this->actingAs($f['admin'])
            ->post("/portal/admissions-pipeline/{$otherLead->id}/advance-stage", ['stage' => AdmissionLead::STAGE_CONTACTED])
            ->assertNotFound();

        $this->actingAs($f['admin'])
            ->post("/portal/admissions-pipeline/{$otherLead->id}/appointments", ['scheduled_at' => now()->addDay()->format('Y-m-d\TH:i')])
            ->assertNotFound();

        $this->actingAs($f['admin'])
            ->post("/portal/admissions-pipeline/{$otherLead->id}/decision", ['decision' => AdmissionDecision::DECISION_ACCEPTED])
            ->assertNotFound();

        $this->assertSame(AdmissionLead::STAGE_NEW, $otherLead->fresh()->stage);
        $this->assertDatabaseCount('admission_appointments', 0);
        $this->assertDatabaseCount('admission_decisions', 0);

        // And the admin's own list never includes the other tenant's lead.
        $this->actingAs($f['admin'])
            ->get(route('admissions-pipeline.index'))
            ->assertInertia(fn ($page) => $page
                ->component('portal/admissions-pipeline/index')
                ->has('leads', 0)
            );
    }
}
