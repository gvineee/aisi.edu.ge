<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\Student;
use App\Domain\Academics\Models\TeacherAssignment;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantInvitation;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Mail\TenantInvitationMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Covers the members/invite feature: admin/director-only invite + revoke,
 * the public accept-invitation flow, and that accepting a guardian/teacher
 * invite creates the matching GuardianLink/TeacherAssignment — not just a
 * bare TenantMembership. See CLAUDE.md invariants #1/#2 (tenant isolation),
 * #3 (guardian/teacher access is verified through the real link/assignment
 * row), and #7 (role and guardian-link changes are audit-logged).
 */
class MemberInvitationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{tenant: Tenant, admin: User, teacher: User, student: Student, schoolClass: SchoolClass}
     */
    private function baseFixtures(): array
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;

        $year = $tenant->academicYears()->create([
            'name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-15', 'is_current' => true,
        ]);
        $schoolClass = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => 'VI']);

        $studentUser = User::factory()->create();
        $student = $tenant->students()->create([
            'school_class_id' => $schoolClass->id, 'user_id' => $studentUser->id,
            'first_name' => 'გიორგი', 'last_name' => 'მ.', 'is_active' => true,
        ]);

        $admin = User::factory()->create();
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $admin->id, 'role' => TenantMembership::ROLE_ADMIN, 'is_active' => true]);

        $teacher = User::factory()->create();
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $teacher->id, 'role' => TenantMembership::ROLE_TEACHER, 'is_active' => true]);

        return compact('tenant', 'admin', 'teacher', 'student', 'schoolClass');
    }

    public function test_non_admin_cannot_invite(): void
    {
        ['teacher' => $teacher, 'schoolClass' => $schoolClass] = $this->baseFixtures();

        $this->actingAs($teacher)->post('/portal/members/invite', [
            'email' => 'newcomer@example.test',
            'role' => TenantMembership::ROLE_TEACHER,
            'school_class_id' => $schoolClass->id,
        ])->assertForbidden();
    }

    public function test_non_admin_cannot_revoke(): void
    {
        ['teacher' => $teacher] = $this->baseFixtures();

        $otherMembership = TenantMembership::where('user_id', $teacher->id)->firstOrFail();

        $this->actingAs($teacher)->post("/portal/members/{$otherMembership->id}/revoke")
            ->assertForbidden();
    }

    public function test_admin_can_invite_teacher_and_mail_is_sent(): void
    {
        Mail::fake();
        ['tenant' => $tenant, 'admin' => $admin, 'schoolClass' => $schoolClass] = $this->baseFixtures();

        $this->actingAs($admin)->post('/portal/members/invite', [
            'email' => 'new.teacher@example.test',
            'role' => TenantMembership::ROLE_TEACHER,
            'school_class_id' => $schoolClass->id,
            'subject' => 'მათემატიკა',
        ])->assertRedirect(route('members.index'));

        $invitation = TenantInvitation::query()->where('email', 'new.teacher@example.test')->firstOrFail();
        $this->assertSame($tenant->id, $invitation->tenant_id);
        $this->assertSame($schoolClass->id, $invitation->school_class_id);
        $this->assertNotNull($invitation->expires_at);
        $this->assertNull($invitation->accepted_at);

        Mail::assertSent(TenantInvitationMail::class, fn ($mail) => $mail->hasTo('new.teacher@example.test'));

        $this->assertDatabaseHas('audit_events', [
            'tenant_id' => $tenant->id,
            'action' => 'invitation.created',
            'subject_id' => $invitation->id,
        ]);
    }

    public function test_admin_can_invite_guardian_via_form_with_explicit_permission_flags(): void
    {
        Mail::fake();
        ['tenant' => $tenant, 'admin' => $admin, 'student' => $student] = $this->baseFixtures();

        $this->actingAs($admin)->post('/portal/members/invite', [
            'email' => 'form.guardian@example.test',
            'role' => TenantMembership::ROLE_GUARDIAN,
            'student_id' => $student->id,
            'can_view_academic' => '1',
            'can_view_financial' => '1',
        ])->assertRedirect(route('members.index'));

        $invitation = TenantInvitation::query()->where('email', 'form.guardian@example.test')->firstOrFail();
        $this->assertSame($tenant->id, $invitation->tenant_id);
        $this->assertSame($student->id, $invitation->student_id);
        $this->assertTrue($invitation->can_view_academic);
        $this->assertTrue($invitation->can_view_financial);
        $this->assertFalse($invitation->can_pickup);
    }

    public function test_invite_form_requires_student_for_guardian_role(): void
    {
        ['admin' => $admin] = $this->baseFixtures();

        $this->actingAs($admin)->post('/portal/members/invite', [
            'email' => 'incomplete.guardian@example.test',
            'role' => TenantMembership::ROLE_GUARDIAN,
        ])->assertSessionHasErrors('student_id');
    }

    public function test_accepting_teacher_invite_creates_working_teacher_assignment_and_allows_login(): void
    {
        ['tenant' => $tenant, 'admin' => $admin, 'schoolClass' => $schoolClass] = $this->baseFixtures();

        $invitation = $tenant->invitations()->create([
            'email' => 'brand.new.teacher@example.test',
            'role' => TenantMembership::ROLE_TEACHER,
            'school_class_id' => $schoolClass->id,
            'subject' => 'ბიოლოგია',
            'token' => TenantInvitation::generateToken(),
            'expires_at' => Carbon::now()->addDays(7),
            'invited_by' => $admin->id,
        ]);

        $this->get("/invitations/{$invitation->token}")->assertOk();

        $this->post("/invitations/{$invitation->token}/accept", [
            'name' => 'ახალი მასწავლებელი',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ])->assertRedirect(route('dashboard'));

        $user = User::query()->where('email', 'brand.new.teacher@example.test')->firstOrFail();
        $this->assertNotNull($user->email_verified_at);

        $this->assertTrue(TenantMembership::userHasActiveRole($tenant->id, $user->id, TenantMembership::ROLE_TEACHER));

        $assignment = TeacherAssignment::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('school_class_id', $schoolClass->id)
            ->first();
        $this->assertNotNull($assignment);
        $this->assertSame('ბიოლოგია', $assignment->subject);

        $this->assertNotNull($invitation->fresh()->accepted_at);

        // The new teacher can now actually log in and reach the portal.
        $this->actingAs($user)->get('/dashboard')->assertOk();
    }

    public function test_accepting_guardian_invite_creates_guardian_link_with_correct_permission_flags(): void
    {
        ['tenant' => $tenant, 'admin' => $admin, 'student' => $student] = $this->baseFixtures();

        $invitation = $tenant->invitations()->create([
            'email' => 'parent@example.test',
            'role' => TenantMembership::ROLE_GUARDIAN,
            'student_id' => $student->id,
            'can_view_academic' => true,
            'can_view_financial' => true,
            'can_pickup' => false,
            'can_receive_notifications' => true,
            'token' => TenantInvitation::generateToken(),
            'expires_at' => Carbon::now()->addDays(7),
            'invited_by' => $admin->id,
        ]);

        $this->post("/invitations/{$invitation->token}/accept", [
            'name' => 'მშობელი მშობლიშვილი',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ])->assertRedirect(route('dashboard'));

        $user = User::query()->where('email', 'parent@example.test')->firstOrFail();

        $link = GuardianLink::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('student_id', $student->id)
            ->firstOrFail();

        $this->assertTrue($link->is_active);
        $this->assertTrue($link->can_view_academic);
        $this->assertTrue($link->can_view_financial);
        $this->assertFalse($link->can_pickup);
        $this->assertTrue($link->can_receive_notifications);
    }

    public function test_expired_invitation_is_rejected(): void
    {
        ['tenant' => $tenant, 'admin' => $admin, 'schoolClass' => $schoolClass] = $this->baseFixtures();

        $invitation = $tenant->invitations()->create([
            'email' => 'too.late@example.test',
            'role' => TenantMembership::ROLE_TEACHER,
            'school_class_id' => $schoolClass->id,
            'token' => TenantInvitation::generateToken(),
            'expires_at' => Carbon::now()->subDay(),
            'invited_by' => $admin->id,
        ]);

        $this->get("/invitations/{$invitation->token}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('status', 'expired'));

        $this->post("/invitations/{$invitation->token}/accept", [
            'name' => 'ვინმე',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ]);

        $this->assertDatabaseMissing('users', ['email' => 'too.late@example.test']);
    }

    public function test_already_accepted_invitation_is_rejected(): void
    {
        ['tenant' => $tenant, 'admin' => $admin, 'schoolClass' => $schoolClass] = $this->baseFixtures();

        $invitation = $tenant->invitations()->create([
            'email' => 'already.done@example.test',
            'role' => TenantMembership::ROLE_TEACHER,
            'school_class_id' => $schoolClass->id,
            'token' => TenantInvitation::generateToken(),
            'expires_at' => Carbon::now()->addDays(7),
            'accepted_at' => Carbon::now()->subHour(),
            'invited_by' => $admin->id,
        ]);

        $this->get("/invitations/{$invitation->token}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('status', 'accepted'));

        $this->post("/invitations/{$invitation->token}/accept", [
            'name' => 'ვინმე',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ]);

        $this->assertDatabaseMissing('users', ['email' => 'already.done@example.test']);
    }

    public function test_invitation_token_from_one_tenant_cannot_be_accepted_on_another_tenants_domain(): void
    {
        ['tenant' => $tenantA, 'admin' => $adminA, 'schoolClass' => $schoolClassA] = $this->baseFixtures();

        $invitation = $tenantA->invitations()->create([
            'email' => 'cross.tenant@example.test',
            'role' => TenantMembership::ROLE_TEACHER,
            'school_class_id' => $schoolClassA->id,
            'token' => TenantInvitation::generateToken(),
            'expires_at' => Carbon::now()->addDays(7),
            'invited_by' => $adminA->id,
        ]);

        $tenantB = Tenant::create(['slug' => 'other-school', 'name' => 'Other', 'locale' => 'ka', 'timezone' => 'Asia/Tbilisi', 'is_active' => true]);
        TenantDomain::create(['tenant_id' => $tenantB->id, 'domain' => 'other-school.test', 'is_primary' => true]);

        $this->get("http://other-school.test/invitations/{$invitation->token}", ['Host' => 'other-school.test'])
            ->assertNotFound();

        $this->post("http://other-school.test/invitations/{$invitation->token}/accept", [
            'name' => 'ვინმე',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ], ['Host' => 'other-school.test'])->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'cross.tenant@example.test']);
        $this->assertNull($invitation->fresh()->accepted_at);
    }

    public function test_revoking_a_membership_blocks_subsequent_portal_access(): void
    {
        ['tenant' => $tenant, 'admin' => $admin, 'teacher' => $teacher] = $this->baseFixtures();

        // Before revocation the teacher's active membership resolves to the
        // real teacher dashboard.
        $this->actingAs($teacher)->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('portal/teacher-dashboard'));

        $membership = TenantMembership::where('tenant_id', $tenant->id)->where('user_id', $teacher->id)->firstOrFail();

        $this->actingAs($admin)->post("/portal/members/{$membership->id}/revoke")
            ->assertRedirect(route('members.index'));

        $this->assertFalse($membership->fresh()->is_active);

        $this->assertDatabaseHas('audit_events', [
            'tenant_id' => $tenant->id,
            'action' => 'membership.revoked',
            'subject_id' => $membership->id,
        ]);

        // Dashboard falls back to the honest "no active role" screen once
        // the teacher role is revoked — never a stale teacher dashboard.
        $this->actingAs($teacher)->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('portal/no-role'));
    }

    public function test_admin_can_list_members(): void
    {
        ['admin' => $admin] = $this->baseFixtures();

        $this->actingAs($admin)->get('/portal/members')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('portal/members/index'));
    }
}
