<?php

namespace Tests\Feature\PickupConsent;

use App\Domain\Academics\Models\Student;
use App\Domain\PickupConsent\Models\AuthorizedPickup;
use App\Domain\PickupConsent\Models\ConsentForm;
use App\Domain\PickupConsent\Models\ConsentResponse;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers CLAUDE-PLATFORM-MODULES.md §7's Pickup & Consent module: only a
 * student's own active guardian may manage their authorized pickups or
 * respond to a consent form on their behalf (verified against
 * guardian_links, never a client-supplied student id), and a second
 * response to the same form for the same child updates the existing row
 * instead of creating a duplicate.
 */
class PickupConsentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{tenant: Tenant, guardian: User, otherGuardian: User, student: Student, otherStudent: Student, admin: User}
     */
    private function baseFixtures(): array
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;

        $year = $tenant->academicYears()->create([
            'name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-15', 'is_current' => true,
        ]);
        $class = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => 'VI']);

        $student = $tenant->students()->create([
            'school_class_id' => $class->id, 'first_name' => 'ნიკა', 'last_name' => 'დ.', 'is_active' => true,
        ]);
        $otherStudent = $tenant->students()->create([
            'school_class_id' => $class->id, 'first_name' => 'გიორგი', 'last_name' => 'კ.', 'is_active' => true,
        ]);

        $guardian = User::factory()->create();
        $otherGuardian = User::factory()->create();
        $admin = User::factory()->create();

        foreach ([[$guardian, TenantMembership::ROLE_GUARDIAN], [$otherGuardian, TenantMembership::ROLE_GUARDIAN], [$admin, TenantMembership::ROLE_ADMIN]] as [$user, $role]) {
            TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role, 'is_active' => true]);
        }

        $tenant->guardianLinks()->create([
            'user_id' => $guardian->id, 'student_id' => $student->id,
            'can_view_academic' => true, 'can_view_financial' => false,
            'can_pickup' => true, 'can_receive_notifications' => true, 'is_active' => true,
        ]);
        // otherGuardian is linked to otherStudent only — never to $student.
        $tenant->guardianLinks()->create([
            'user_id' => $otherGuardian->id, 'student_id' => $otherStudent->id,
            'can_view_academic' => true, 'can_view_financial' => false,
            'can_pickup' => true, 'can_receive_notifications' => true, 'is_active' => true,
        ]);

        return compact('tenant', 'guardian', 'otherGuardian', 'student', 'otherStudent', 'admin');
    }

    public function test_guardian_can_add_and_remove_authorized_pickup(): void
    {
        ['guardian' => $guardian, 'student' => $student] = $this->baseFixtures();

        $this->actingAs($guardian)->get('/portal/pickup')->assertOk();

        $this->actingAs($guardian)->post('/portal/pickup', [
            'student_id' => $student->id,
            'full_name' => 'თამარ მაისურაძე',
            'relationship' => 'ბებია',
            'id_document_number' => '01234567890',
        ])->assertRedirect(route('pickup.index'));

        $pickup = AuthorizedPickup::query()->first();
        $this->assertNotNull($pickup);
        $this->assertSame($student->id, $pickup->student_id);
        $this->assertTrue($pickup->is_active);
        $this->assertSame($guardian->id, $pickup->added_by);

        $this->actingAs($guardian)->post("/portal/pickup/{$pickup->id}/remove")
            ->assertRedirect(route('pickup.index'));

        $this->assertFalse($pickup->fresh()->is_active);
    }

    public function test_guardian_cannot_manage_pickup_for_child_that_is_not_theirs(): void
    {
        ['otherGuardian' => $otherGuardian, 'student' => $student] = $this->baseFixtures();

        $this->actingAs($otherGuardian)->post('/portal/pickup', [
            'student_id' => $student->id,
            'full_name' => 'უცხო პირი',
            'relationship' => 'მეზობელი',
        ])->assertForbidden();

        $this->assertSame(0, AuthorizedPickup::query()->count());
    }

    public function test_guardian_cannot_remove_pickup_belonging_to_another_guardians_child(): void
    {
        ['guardian' => $guardian, 'otherGuardian' => $otherGuardian, 'otherStudent' => $otherStudent] = $this->baseFixtures();

        $this->actingAs($otherGuardian)->post('/portal/pickup', [
            'student_id' => $otherStudent->id,
            'full_name' => 'თამარ მაისურაძე',
            'relationship' => 'ბებია',
        ])->assertRedirect();
        $pickup = AuthorizedPickup::query()->first();

        $this->actingAs($guardian)->post("/portal/pickup/{$pickup->id}/remove")->assertForbidden();
        $this->assertTrue($pickup->fresh()->is_active);
    }

    public function test_revoked_guardian_link_loses_pickup_access(): void
    {
        ['tenant' => $tenant, 'guardian' => $guardian, 'student' => $student] = $this->baseFixtures();

        $tenant->guardianLinks()->where('user_id', $guardian->id)->where('student_id', $student->id)->update(['is_active' => false]);

        $this->actingAs($guardian)->post('/portal/pickup', [
            'student_id' => $student->id,
            'full_name' => 'ვინმე',
            'relationship' => 'ნათესავი',
        ])->assertForbidden();
    }

    /**
     * can_pickup is a separate, independently revocable permission bit on
     * guardian_links (default false) — an active link alone must not grant
     * pickup authority (CLAUDE.md invariant #3).
     */
    public function test_guardian_with_active_link_but_can_pickup_disabled_is_forbidden(): void
    {
        ['tenant' => $tenant, 'guardian' => $guardian, 'student' => $student] = $this->baseFixtures();

        $tenant->guardianLinks()->where('user_id', $guardian->id)->where('student_id', $student->id)->update(['can_pickup' => false]);

        $this->actingAs($guardian)->get('/portal/pickup')->assertOk();

        $this->actingAs($guardian)->post('/portal/pickup', [
            'student_id' => $student->id,
            'full_name' => 'ვინმე',
            'relationship' => 'ნათესავი',
        ])->assertForbidden();

        $this->assertSame(0, AuthorizedPickup::query()->count());

        // Give another, pickup-enabled guardian a pickup entry, then confirm
        // the can_pickup=false guardian still cannot remove it either.
        $otherGuardian = User::factory()->create();
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $otherGuardian->id, 'role' => TenantMembership::ROLE_GUARDIAN, 'is_active' => true]);
        $tenant->guardianLinks()->create([
            'user_id' => $otherGuardian->id, 'student_id' => $student->id,
            'can_view_academic' => true, 'can_view_financial' => false,
            'can_pickup' => true, 'can_receive_notifications' => true, 'is_active' => true,
        ]);

        $this->actingAs($otherGuardian)->post('/portal/pickup', [
            'student_id' => $student->id,
            'full_name' => 'თამარ მაისურაძე',
            'relationship' => 'ბებია',
        ])->assertRedirect(route('pickup.index'));
        $pickup = AuthorizedPickup::query()->firstOrFail();

        $this->actingAs($guardian)->post("/portal/pickup/{$pickup->id}/remove")->assertForbidden();
        $this->assertTrue($pickup->fresh()->is_active);
    }

    public function test_admin_can_publish_consent_form_and_guardian_can_respond(): void
    {
        ['admin' => $admin, 'guardian' => $guardian, 'student' => $student] = $this->baseFixtures();

        $this->actingAs($admin)->post('/portal/consents', [
            'title' => 'სასკოლო ექსკურსია',
            'body' => 'გთხოვთ, დაადასტუროთ თანხმობა შვილის მონაწილეობაზე.',
            'requires_signature' => true,
        ])->assertRedirect(route('consents.index'));

        $form = ConsentForm::query()->first();
        $this->assertNotNull($form);
        $this->assertSame($admin->id, $form->created_by);

        $this->actingAs($admin)->get('/portal/consents')->assertOk();
        $this->actingAs($admin)->get("/portal/consents/{$form->id}/roster")->assertOk();

        $this->actingAs($guardian)->get('/portal/consents')->assertOk();

        $this->actingAs($guardian)->post("/portal/consents/{$form->id}/respond", [
            'student_id' => $student->id,
            'granted' => true,
        ])->assertRedirect(route('consents.index'));

        $response = ConsentResponse::query()->first();
        $this->assertNotNull($response);
        $this->assertTrue($response->granted);
        $this->assertSame($guardian->id, $response->guardian_id);
        $this->assertSame($student->id, $response->student_id);
        $this->assertNotNull($response->responded_at);
    }

    public function test_responding_again_updates_existing_response_not_duplicate(): void
    {
        ['admin' => $admin, 'guardian' => $guardian, 'student' => $student] = $this->baseFixtures();

        $this->actingAs($admin)->post('/portal/consents', [
            'title' => 'ფოტოზე თანხმობა',
            'body' => 'თანხმობა ფოტოს გამოქვეყნებაზე.',
        ]);
        $form = ConsentForm::query()->first();

        $this->actingAs($guardian)->post("/portal/consents/{$form->id}/respond", [
            'student_id' => $student->id,
            'granted' => true,
        ])->assertRedirect();
        $this->assertSame(1, ConsentResponse::query()->count());
        $firstResponseId = ConsentResponse::query()->firstOrFail()->id;

        // Respond again, this time declining — must update the same row.
        $this->actingAs($guardian)->post("/portal/consents/{$form->id}/respond", [
            'student_id' => $student->id,
            'granted' => false,
        ])->assertRedirect();

        $this->assertSame(1, ConsentResponse::query()->count());
        $response = ConsentResponse::query()->firstOrFail();
        $this->assertSame($firstResponseId, $response->id);
        $this->assertFalse($response->granted);
    }

    public function test_guardian_cannot_respond_to_consent_for_child_that_is_not_theirs(): void
    {
        ['admin' => $admin, 'otherGuardian' => $otherGuardian, 'student' => $student] = $this->baseFixtures();

        $this->actingAs($admin)->post('/portal/consents', [
            'title' => 'სასკოლო ექსკურსია',
            'body' => 'თანხმობის ტექსტი.',
        ]);
        $form = ConsentForm::query()->first();

        $this->actingAs($otherGuardian)->post("/portal/consents/{$form->id}/respond", [
            'student_id' => $student->id,
            'granted' => true,
        ])->assertForbidden();

        $this->assertSame(0, ConsentResponse::query()->count());
    }

    public function test_guardian_cannot_publish_consent_form(): void
    {
        ['guardian' => $guardian] = $this->baseFixtures();

        $this->actingAs($guardian)->post('/portal/consents', [
            'title' => 'არასწორი მცდელობა',
            'body' => 'ტექსტი.',
        ])->assertForbidden();

        $this->assertSame(0, ConsentForm::query()->count());
    }

    public function test_tenant_isolation_on_pickup_and_consents(): void
    {
        ['tenant' => $tenant, 'guardian' => $guardian, 'admin' => $admin, 'student' => $student] = $this->baseFixtures();

        $this->actingAs($guardian)->post('/portal/pickup', [
            'student_id' => $student->id,
            'full_name' => 'თამარ მაისურაძე',
            'relationship' => 'ბებია',
        ]);
        $pickup = AuthorizedPickup::query()->first();

        $this->actingAs($admin)->post('/portal/consents', [
            'title' => 'სასკოლო ექსკურსია',
            'body' => 'ტექსტი.',
        ]);
        $form = ConsentForm::query()->first();

        $otherTenant = Tenant::create(['slug' => 'other-school', 'name' => 'Other', 'locale' => 'ka', 'timezone' => 'Asia/Tbilisi', 'is_active' => true]);
        TenantDomain::create(['tenant_id' => $otherTenant->id, 'domain' => 'other-school.test', 'is_primary' => true]);
        $outsider = User::factory()->create();
        TenantMembership::create(['tenant_id' => $otherTenant->id, 'user_id' => $outsider->id, 'role' => TenantMembership::ROLE_ADMIN, 'is_active' => true]);

        $this->actingAs($outsider)
            ->get("http://other-school.test/portal/consents/{$form->id}/roster", ['Host' => 'other-school.test'])
            ->assertNotFound();

        $this->actingAs($outsider)
            ->post("http://other-school.test/portal/pickup/{$pickup->id}/remove", [], ['Host' => 'other-school.test'])
            ->assertNotFound();

        $this->assertSame($tenant->id, $pickup->tenant_id);
        $this->assertSame($tenant->id, $form->tenant_id);
    }
}
