<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Academics\Models\Student;
use App\Domain\Tenancy\Actions\DecideEnrollmentVerificationRequest;
use App\Domain\Tenancy\Models\EnrollmentVerificationRequest;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the self-service "I am this student" / "I am this student's
 * guardian" enrollment-verification flow: a just-registered account claims
 * an identity against the tenant's existing Student roster, and only an
 * admin/director's approval ({@see DecideEnrollmentVerificationRequest})
 * turns that into real portal access. CLAUDE.md invariant #1/#2 (tenant
 * isolation), #3 (guardian access via a real GuardianLink, not a bare role),
 * #7 (audit logged).
 */
class EnrollmentVerificationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{tenant: Tenant, admin: User}
     */
    private function baseFixtures(): array
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;

        $admin = User::factory()->create();
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $admin->id, 'role' => TenantMembership::ROLE_ADMIN, 'is_active' => true]);

        return compact('tenant', 'admin');
    }

    public function test_student_registers_with_correct_national_id_and_is_auto_matched(): void
    {
        ['tenant' => $tenant] = $this->baseFixtures();

        $student = $tenant->students()->create([
            'first_name' => 'ნინო', 'last_name' => 'კვარაცხელია', 'national_id' => '01234567890', 'is_active' => true,
        ]);

        $applicant = User::factory()->create();

        $this->actingAs($applicant)->post('/portal/verify-enrollment', [
            'requested_role' => 'student',
            'national_id' => '01234567890',
            'first_name' => 'სხვა', // The submitted name need not match once national_id resolves it.
            'last_name' => 'სახელი',
        ])->assertRedirect(route('enrollment-verification.show'));

        $request = EnrollmentVerificationRequest::query()->where('user_id', $applicant->id)->firstOrFail();
        $this->assertSame($student->id, $request->matched_student_id);
        $this->assertSame(EnrollmentVerificationRequest::STATUS_PENDING, $request->status);
    }

    public function test_guardian_registers_with_childs_national_id_and_is_auto_matched(): void
    {
        ['tenant' => $tenant] = $this->baseFixtures();

        $student = $tenant->students()->create([
            'first_name' => 'გიორგი', 'last_name' => 'ბერიძე', 'national_id' => '11122233344', 'is_active' => true,
        ]);

        $parent = User::factory()->create();

        $this->actingAs($parent)->post('/portal/verify-enrollment', [
            'requested_role' => 'guardian',
            'national_id' => '11122233344',
            'first_name' => 'გიორგი',
            'last_name' => 'ბერიძე',
        ])->assertRedirect(route('enrollment-verification.show'));

        $request = EnrollmentVerificationRequest::query()->where('user_id', $parent->id)->firstOrFail();
        $this->assertSame($student->id, $request->matched_student_id);
    }

    public function test_unknown_national_id_still_creates_an_unmatched_request(): void
    {
        ['tenant' => $tenant] = $this->baseFixtures();
        $tenant->students()->create(['first_name' => 'სხვა', 'last_name' => 'მოსწავლე', 'national_id' => '99999999999', 'is_active' => true]);

        $applicant = User::factory()->create();

        $this->actingAs($applicant)->post('/portal/verify-enrollment', [
            'requested_role' => 'student',
            'national_id' => '00000000000',
            'first_name' => 'უცნობი',
            'last_name' => 'პირი',
        ])->assertRedirect();

        $request = EnrollmentVerificationRequest::query()->where('user_id', $applicant->id)->firstOrFail();
        $this->assertNull($request->matched_student_id);
        $this->assertSame(EnrollmentVerificationRequest::STATUS_PENDING, $request->status);
    }

    public function test_name_only_match_requires_a_single_unambiguous_candidate(): void
    {
        ['tenant' => $tenant] = $this->baseFixtures();

        $unique = $tenant->students()->create(['first_name' => 'ლუკა', 'last_name' => 'დათოშვილი', 'is_active' => true]);
        $tenant->students()->create(['first_name' => 'თამარ', 'last_name' => 'ხარაზი', 'is_active' => true]);
        $tenant->students()->create(['first_name' => 'თამარ', 'last_name' => 'ხარაზი', 'is_active' => true]);

        $studentApplicant = User::factory()->create();
        $this->actingAs($studentApplicant)->post('/portal/verify-enrollment', [
            'requested_role' => 'student', 'first_name' => 'ლუკა', 'last_name' => 'დათოშვილი',
        ]);
        $uniqueRequest = EnrollmentVerificationRequest::query()->where('user_id', $studentApplicant->id)->firstOrFail();
        $this->assertSame($unique->id, $uniqueRequest->matched_student_id);

        $ambiguousApplicant = User::factory()->create();
        $this->actingAs($ambiguousApplicant)->post('/portal/verify-enrollment', [
            'requested_role' => 'student', 'first_name' => 'თამარ', 'last_name' => 'ხარაზი',
        ]);
        $ambiguousRequest = EnrollmentVerificationRequest::query()->where('user_id', $ambiguousApplicant->id)->firstOrFail();
        $this->assertNull($ambiguousRequest->matched_student_id);
    }

    public function test_duplicate_pending_request_is_rejected(): void
    {
        $this->baseFixtures();
        $applicant = User::factory()->create();

        $this->actingAs($applicant)->post('/portal/verify-enrollment', [
            'requested_role' => 'student', 'first_name' => 'ა', 'last_name' => 'ბ',
        ])->assertRedirect();

        $this->actingAs($applicant)->post('/portal/verify-enrollment', [
            'requested_role' => 'guardian', 'first_name' => 'ა', 'last_name' => 'ბ',
        ])->assertSessionHasErrors('requested_role');

        $this->assertSame(1, EnrollmentVerificationRequest::query()->where('user_id', $applicant->id)->count());
    }

    public function test_non_admin_cannot_decide(): void
    {
        ['tenant' => $tenant] = $this->baseFixtures();
        $teacher = User::factory()->create();
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $teacher->id, 'role' => TenantMembership::ROLE_TEACHER, 'is_active' => true]);

        $applicant = User::factory()->create();
        $request = $tenant->enrollmentVerificationRequests()->create([
            'user_id' => $applicant->id, 'requested_role' => 'student',
            'submitted_first_name' => 'ა', 'submitted_last_name' => 'ბ', 'status' => 'pending',
        ]);

        $this->actingAs($teacher)->post("/portal/members/enrollment-requests/{$request->id}/decide", [
            'decision' => 'approve',
        ])->assertForbidden();
    }

    public function test_approving_a_matched_student_request_links_the_student_record_and_allows_login(): void
    {
        ['tenant' => $tenant, 'admin' => $admin] = $this->baseFixtures();
        $student = $tenant->students()->create(['first_name' => 'დავით', 'last_name' => 'მაისურაძე', 'national_id' => '55566677788', 'is_active' => true]);

        $applicant = User::factory()->create();
        $this->actingAs($applicant)->post('/portal/verify-enrollment', [
            'requested_role' => 'student', 'national_id' => '55566677788', 'first_name' => 'დავით', 'last_name' => 'მაისურაძე',
        ]);
        $request = EnrollmentVerificationRequest::query()->where('user_id', $applicant->id)->firstOrFail();

        $this->actingAs($admin)->post("/portal/members/enrollment-requests/{$request->id}/decide", [
            'decision' => 'approve',
        ])->assertRedirect(route('members.index'));

        $this->assertSame($applicant->id, $student->fresh()->user_id);
        $this->assertTrue(TenantMembership::userHasActiveRole($tenant->id, $applicant->id, TenantMembership::ROLE_STUDENT));
        $this->assertSame(EnrollmentVerificationRequest::STATUS_APPROVED, $request->fresh()->status);

        $this->assertDatabaseHas('audit_events', [
            'tenant_id' => $tenant->id, 'action' => 'enrollment_verification.approved', 'subject_id' => $request->id,
        ]);

        $this->actingAs($applicant)->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('portal/student-dashboard')->where('linked', true));
    }

    public function test_approving_a_matched_guardian_request_creates_guardian_link_with_default_permissions(): void
    {
        ['tenant' => $tenant, 'admin' => $admin] = $this->baseFixtures();
        $student = $tenant->students()->create(['first_name' => 'ანა', 'last_name' => 'წერეთელი', 'national_id' => '22233344455', 'is_active' => true]);

        $parent = User::factory()->create();
        $this->actingAs($parent)->post('/portal/verify-enrollment', [
            'requested_role' => 'guardian', 'national_id' => '22233344455', 'first_name' => 'ანა', 'last_name' => 'წერეთელი',
        ]);
        $request = EnrollmentVerificationRequest::query()->where('user_id', $parent->id)->firstOrFail();

        $this->actingAs($admin)->post("/portal/members/enrollment-requests/{$request->id}/decide", [
            'decision' => 'approve',
        ])->assertRedirect(route('members.index'));

        $link = GuardianLink::query()->where('tenant_id', $tenant->id)->where('user_id', $parent->id)->where('student_id', $student->id)->firstOrFail();
        $this->assertTrue($link->is_active);
        $this->assertTrue($link->can_view_academic);
        $this->assertFalse($link->can_view_financial);
        $this->assertFalse($link->can_pickup);
        $this->assertTrue($link->can_receive_notifications);
    }

    public function test_approving_an_unmatched_request_requires_a_manual_student_override(): void
    {
        ['tenant' => $tenant, 'admin' => $admin] = $this->baseFixtures();
        $student = $tenant->students()->create(['first_name' => 'ვინმე', 'last_name' => 'მოსწავლე', 'is_active' => true]);

        $applicant = User::factory()->create();
        $this->actingAs($applicant)->post('/portal/verify-enrollment', [
            'requested_role' => 'student', 'first_name' => 'არასწორი', 'last_name' => 'სახელი',
        ]);
        $request = EnrollmentVerificationRequest::query()->where('user_id', $applicant->id)->firstOrFail();
        $this->assertNull($request->matched_student_id);

        // Without an override the approval is rejected, not silently guessed.
        $this->actingAs($admin)->post("/portal/members/enrollment-requests/{$request->id}/decide", [
            'decision' => 'approve',
        ])->assertSessionHasErrors('student_id');
        $this->assertSame(EnrollmentVerificationRequest::STATUS_PENDING, $request->fresh()->status);

        $this->actingAs($admin)->post("/portal/members/enrollment-requests/{$request->id}/decide", [
            'decision' => 'approve', 'student_id' => $student->id,
        ])->assertRedirect(route('members.index'));

        $this->assertSame($applicant->id, $student->fresh()->user_id);
        $this->assertSame($student->id, $request->fresh()->matched_student_id);
    }

    public function test_rejecting_a_request_creates_no_membership_and_can_be_resubmitted(): void
    {
        ['tenant' => $tenant, 'admin' => $admin] = $this->baseFixtures();

        $applicant = User::factory()->create();
        $this->actingAs($applicant)->post('/portal/verify-enrollment', [
            'requested_role' => 'student', 'first_name' => 'არასწორი', 'last_name' => 'პრეტენზია',
        ]);
        $request = EnrollmentVerificationRequest::query()->where('user_id', $applicant->id)->firstOrFail();

        $this->actingAs($admin)->post("/portal/members/enrollment-requests/{$request->id}/decide", [
            'decision' => 'reject', 'reason' => 'მონაცემები არ ემთხვევა.',
        ])->assertRedirect(route('members.index'));

        $this->assertSame(EnrollmentVerificationRequest::STATUS_REJECTED, $request->fresh()->status);
        $this->assertFalse(TenantMembership::userHasActiveRole($tenant->id, $applicant->id, TenantMembership::ROLE_STUDENT));

        // A rejected applicant may submit a fresh claim.
        $this->actingAs($applicant)->post('/portal/verify-enrollment', [
            'requested_role' => 'student', 'first_name' => 'ახალი', 'last_name' => 'მცდელობა',
        ])->assertRedirect();
        $this->assertSame(2, EnrollmentVerificationRequest::query()->where('user_id', $applicant->id)->count());
    }

    public function test_a_student_already_linked_to_a_user_cannot_be_claimed_again_by_a_new_student_request(): void
    {
        ['tenant' => $tenant] = $this->baseFixtures();
        $existingUser = User::factory()->create();
        $student = $tenant->students()->create([
            'first_name' => 'დაკავებული', 'last_name' => 'მოსწავლე', 'national_id' => '33344455566',
            'user_id' => $existingUser->id, 'is_active' => true,
        ]);

        $impersonator = User::factory()->create();
        $this->actingAs($impersonator)->post('/portal/verify-enrollment', [
            'requested_role' => 'student', 'national_id' => '33344455566', 'first_name' => 'დაკავებული', 'last_name' => 'მოსწავლე',
        ]);

        $request = EnrollmentVerificationRequest::query()->where('user_id', $impersonator->id)->firstOrFail();
        $this->assertNull($request->matched_student_id);
        $this->assertSame($existingUser->id, $student->fresh()->user_id);
    }

    public function test_national_id_match_never_crosses_tenant_boundary(): void
    {
        ['tenant' => $tenantA] = $this->baseFixtures();
        $tenantA->students()->create(['first_name' => 'ა', 'last_name' => 'ბ', 'national_id' => '77788899900', 'is_active' => true]);

        $tenantB = Tenant::create(['slug' => 'other-school', 'name' => 'Other', 'locale' => 'ka', 'timezone' => 'Asia/Tbilisi', 'is_active' => true]);
        TenantDomain::create(['tenant_id' => $tenantB->id, 'domain' => 'other-school.test', 'is_primary' => true]);
        $studentB = $tenantB->students()->create(['first_name' => 'გ', 'last_name' => 'დ', 'national_id' => '77788899900', 'is_active' => true]);

        $applicant = User::factory()->create();
        $this->actingAs($applicant)
            ->post('http://other-school.test/portal/verify-enrollment', [
                'requested_role' => 'student', 'national_id' => '77788899900', 'first_name' => 'გ', 'last_name' => 'დ',
            ], ['Host' => 'other-school.test']);

        $request = EnrollmentVerificationRequest::query()->where('user_id', $applicant->id)->firstOrFail();
        $this->assertSame($tenantB->id, $request->tenant_id);
        $this->assertSame($studentB->id, $request->matched_student_id);
    }
}
