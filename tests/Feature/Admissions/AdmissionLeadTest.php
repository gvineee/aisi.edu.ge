<?php

namespace Tests\Feature\Admissions;

use App\Domain\Admissions\Models\AdmissionLead;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Http\Requests\Public\StoreAdmissionLeadRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdmissionLeadTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_valid_lead_is_stored_against_the_resolved_tenant(): void
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;

        $response = $this->post(route('admissions.leads.store'), [
            'guardian_name' => 'მარიამ კ.',
            'contact_method' => 'phone',
            'contact_value' => '+995500000000',
            'desired_grade' => 'VI კლასი',
            'consent_given' => true,
        ]);

        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('admission_leads', [
            'tenant_id' => $tenant->id,
            'guardian_name' => 'მარიამ კ.',
            'contact_value' => '+995500000000',
            'stage' => AdmissionLead::STAGE_NEW,
        ]);
    }

    public function test_it_rejects_a_submission_without_consent(): void
    {
        $response = $this->post(route('admissions.leads.store'), [
            'guardian_name' => 'მარიამ კ.',
            'contact_method' => 'phone',
            'contact_value' => '+995500000000',
            'consent_given' => false,
        ]);

        $response->assertSessionHasErrors('consent_given');
        $this->assertDatabaseCount('admission_leads', 0);
    }

    public function test_it_does_not_ask_for_or_accept_a_child_personal_id_field(): void
    {
        // The spec (docs/02 section 5.1) is explicit: the short lead form
        // must not collect a child's personal ID. Confirm the validated
        // payload has no such field to accidentally persist.
        $rules = (new StoreAdmissionLeadRequest)->rules();

        $this->assertArrayNotHasKey('child_personal_id', $rules);
        $this->assertArrayNotHasKey('child_id_number', $rules);
    }

    public function test_a_second_lead_with_the_same_contact_within_a_day_is_flagged_as_a_duplicate(): void
    {
        $this->post(route('admissions.leads.store'), [
            'guardian_name' => 'მარიამ კ.',
            'contact_method' => 'phone',
            'contact_value' => '+995500000001',
            'consent_given' => true,
        ])->assertSessionHasNoErrors();

        $first = AdmissionLead::query()->where('contact_value', '+995500000001')->firstOrFail();

        $this->post(route('admissions.leads.store'), [
            'guardian_name' => 'მარიამ კ.',
            'contact_method' => 'phone',
            'contact_value' => '+995500000001',
            'consent_given' => true,
        ])->assertSessionHasNoErrors();

        $second = AdmissionLead::query()
            ->where('contact_value', '+995500000001')
            ->where('id', '!=', $first->id)
            ->firstOrFail();

        $this->assertSame($first->id, $second->duplicate_of_lead_id);
        $this->assertNotNull($second->duplicate_of_checked_at);
    }

    public function test_requests_are_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('admissions.leads.store'), [
                'guardian_name' => 'Rate Limit Test',
                'contact_method' => 'phone',
                'contact_value' => "+99550000000{$i}",
                'consent_given' => true,
            ]);
        }

        $response = $this->post(route('admissions.leads.store'), [
            'guardian_name' => 'Rate Limit Test',
            'contact_method' => 'phone',
            'contact_value' => '+995500000099',
            'consent_given' => true,
        ]);

        $response->assertStatus(429);
    }
}
