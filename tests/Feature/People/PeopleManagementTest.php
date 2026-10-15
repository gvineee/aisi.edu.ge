<?php

namespace Tests\Feature\People;

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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Manual people management by an admin after the first invitation:
 * student account linking, guardian links and permissions, teacher
 * class/subject assignments, roster edits and invitation re-sends.
 * Covers tenant isolation (#1/#2), guardian/teacher access through real
 * link rows (#3) and audit of every change (#7).
 */
class PeopleManagementTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private SchoolClass $classA;

    private SchoolClass $classB;

    private Student $student;

    private Student $sibling;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;
        $year = $this->tenant->academicYears()->create([
            'name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-15', 'is_current' => true,
        ]);
        $this->classA = $this->tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => 'TEST-A']);
        $this->classB = $this->tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => 'TEST-B']);
        $this->student = $this->tenant->students()->create([
            'school_class_id' => $this->classA->id, 'first_name' => 'სატესტო', 'last_name' => 'მოსწავლე', 'is_active' => true,
        ]);
        $this->sibling = $this->tenant->students()->create([
            'school_class_id' => $this->classB->id, 'first_name' => 'სატესტო', 'last_name' => 'და', 'is_active' => true,
        ]);

        $this->admin = User::factory()->create();
        $this->membership($this->admin, TenantMembership::ROLE_ADMIN);
    }

    private function membership(User $user, string $role, ?Tenant $tenant = null): TenantMembership
    {
        return TenantMembership::create([
            'tenant_id' => ($tenant ?? $this->tenant)->id, 'user_id' => $user->id, 'role' => $role, 'is_active' => true,
        ]);
    }

    private function otherTenant(): Tenant
    {
        return Tenant::create(['slug' => 'other-school', 'name' => 'Other', 'locale' => 'ka', 'timezone' => 'Asia/Tbilisi', 'is_active' => true]);
    }

    public function test_student_invitation_links_the_roster_row_on_accept(): void
    {
        Mail::fake();

        $this->actingAs($this->admin)->post('/portal/members/invite', [
            'email' => 'pupil@example.test',
            'role' => TenantMembership::ROLE_STUDENT,
            'student_id' => $this->student->id,
        ])->assertRedirect(route('members.index'));

        $invitation = TenantInvitation::query()->where('email', 'pupil@example.test')->firstOrFail();
        $this->assertSame($this->student->id, $invitation->student_id);

        auth()->logout();
        $this->post("/invitations/{$invitation->token}/accept", [
            'name' => 'Pupil', 'password' => 'Str0ng-Passw0rd!x', 'password_confirmation' => 'Str0ng-Passw0rd!x',
        ])->assertRedirect(route('dashboard'));

        $user = User::query()->where('email', 'pupil@example.test')->firstOrFail();
        $this->assertSame($user->id, $this->student->fresh()->user_id);
        $this->assertDatabaseHas('audit_events', ['action' => 'student.account_linked', 'subject_id' => $this->student->id]);
    }

    public function test_student_invitation_is_refused_when_roster_row_already_has_an_account(): void
    {
        $existing = User::factory()->create();
        $this->student->update(['user_id' => $existing->id]);

        $this->actingAs($this->admin)->post('/portal/members/invite', [
            'email' => 'second@example.test',
            'role' => TenantMembership::ROLE_STUDENT,
            'student_id' => $this->student->id,
        ])->assertSessionHasErrors('student_id');

        $this->assertDatabaseMissing('tenant_invitations', ['email' => 'second@example.test']);
    }

    public function test_accepting_student_invitation_fails_cleanly_if_row_was_claimed_meanwhile(): void
    {
        Mail::fake();
        $this->actingAs($this->admin)->post('/portal/members/invite', [
            'email' => 'late@example.test', 'role' => TenantMembership::ROLE_STUDENT, 'student_id' => $this->student->id,
        ]);
        $invitation = TenantInvitation::query()->where('email', 'late@example.test')->firstOrFail();

        $this->student->update(['user_id' => User::factory()->create()->id]);

        auth()->logout();
        $this->post("/invitations/{$invitation->token}/accept", [
            'name' => 'Late', 'password' => 'Str0ng-Passw0rd!x', 'password_confirmation' => 'Str0ng-Passw0rd!x',
        ])->assertSessionHasErrors('invitation');

        // Nothing half-applied: no user, no membership, invitation still open.
        $this->assertDatabaseMissing('users', ['email' => 'late@example.test']);
        $this->assertNull($invitation->fresh()->accepted_at);
    }

    public function test_admin_links_existing_guardian_to_second_child_and_edits_permissions(): void
    {
        $guardian = User::factory()->create();
        $guardianMembership = $this->membership($guardian, TenantMembership::ROLE_GUARDIAN);
        GuardianLink::forceCreate([
            'tenant_id' => $this->tenant->id, 'user_id' => $guardian->id, 'student_id' => $this->student->id,
            'can_view_academic' => true, 'can_view_financial' => false, 'can_pickup' => false, 'can_receive_notifications' => true, 'is_active' => true,
        ]);

        $this->actingAs($this->admin)->post("/portal/members/{$guardianMembership->id}/guardian-links", [
            'student_id' => $this->sibling->id,
            'can_view_academic' => '1',
            'can_view_financial' => '1',
        ])->assertRedirect(route('members.index'));

        $link = GuardianLink::query()->where('user_id', $guardian->id)->where('student_id', $this->sibling->id)->firstOrFail();
        $this->assertTrue($link->can_view_financial);
        $this->assertFalse($link->can_pickup);
        $this->assertDatabaseHas('audit_events', ['action' => 'guardian_link.created', 'subject_id' => $link->id]);

        $this->actingAs($this->admin)->put("/portal/members/guardian-links/{$link->id}", [
            'is_active' => '1',
            'can_view_academic' => '1',
            'can_pickup' => '1',
        ])->assertRedirect(route('members.index'));

        $link->refresh();
        $this->assertTrue($link->can_pickup);
        $this->assertFalse($link->can_view_financial);
        $this->assertDatabaseHas('audit_events', ['action' => 'guardian_link.permissions_updated', 'subject_id' => $link->id]);
    }

    public function test_deactivated_guardian_link_removes_access_and_is_audited(): void
    {
        $guardian = User::factory()->create();
        $this->membership($guardian, TenantMembership::ROLE_GUARDIAN);
        $link = GuardianLink::forceCreate([
            'tenant_id' => $this->tenant->id, 'user_id' => $guardian->id, 'student_id' => $this->student->id,
            'can_view_academic' => true, 'can_view_financial' => true, 'can_pickup' => true, 'can_receive_notifications' => true, 'is_active' => true,
        ]);

        $this->actingAs($this->admin)->put("/portal/members/guardian-links/{$link->id}", [
            'is_active' => '0',
            'can_view_academic' => '1',
        ])->assertRedirect(route('members.index'));

        $this->assertFalse($link->fresh()->is_active);
        $this->assertDatabaseHas('audit_events', ['action' => 'guardian_link.deactivated', 'subject_id' => $link->id]);

        // The guardian can no longer open the child's progress page.
        $this->actingAs($guardian)->get("/portal/students/{$this->student->id}/progress")->assertForbidden();
    }

    public function test_guardian_link_cannot_target_a_non_guardian_or_foreign_student(): void
    {
        $teacher = User::factory()->create();
        $teacherMembership = $this->membership($teacher, TenantMembership::ROLE_TEACHER);

        $this->actingAs($this->admin)->post("/portal/members/{$teacherMembership->id}/guardian-links", [
            'student_id' => $this->student->id,
        ])->assertSessionHasErrors('student_id');

        $guardian = User::factory()->create();
        $guardianMembership = $this->membership($guardian, TenantMembership::ROLE_GUARDIAN);
        $tenantB = $this->otherTenant();
        $yearB = $tenantB->academicYears()->create(['name' => 'B', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-15', 'is_current' => true]);
        $classB = $tenantB->schoolClasses()->create(['academic_year_id' => $yearB->id, 'name' => 'B']);
        $foreignStudent = $tenantB->students()->create(['school_class_id' => $classB->id, 'first_name' => 'X', 'last_name' => 'Y', 'is_active' => true]);

        $this->actingAs($this->admin)->post("/portal/members/{$guardianMembership->id}/guardian-links", [
            'student_id' => $foreignStudent->id,
        ])->assertNotFound();

        $this->assertDatabaseMissing('guardian_links', ['student_id' => $foreignStudent->id]);
    }

    public function test_non_admin_cannot_manage_links_or_assignments(): void
    {
        $teacher = User::factory()->create();
        $teacherMembership = $this->membership($teacher, TenantMembership::ROLE_TEACHER);

        $this->actingAs($teacher)->post("/portal/members/{$teacherMembership->id}/teacher-assignments", [
            'school_class_id' => $this->classA->id, 'subject' => 'მათემატიკა',
        ])->assertForbidden();

        $this->actingAs($teacher)->put("/portal/academic-structure/students/{$this->student->id}", [
            'school_class_id' => $this->classB->id, 'first_name' => 'A', 'last_name' => 'B', 'is_active' => '1',
        ])->assertForbidden();

        $this->assertSame($this->classA->id, $this->student->fresh()->school_class_id);
    }

    public function test_admin_adds_and_removes_teacher_assignments(): void
    {
        $teacher = User::factory()->create();
        $teacherMembership = $this->membership($teacher, TenantMembership::ROLE_TEACHER);

        $this->actingAs($this->admin)->post("/portal/members/{$teacherMembership->id}/teacher-assignments", [
            'school_class_id' => $this->classB->id, 'subject' => 'ისტორია',
        ])->assertRedirect(route('members.index'));

        $assignment = TeacherAssignment::query()->where('user_id', $teacher->id)->where('school_class_id', $this->classB->id)->firstOrFail();
        $this->assertSame('ისტორია', $assignment->subject);
        $this->assertDatabaseHas('audit_events', ['action' => 'teacher_assignment.created', 'subject_id' => $assignment->id]);

        // Same class + subject twice is a validation error, not a 500.
        $this->actingAs($this->admin)->post("/portal/members/{$teacherMembership->id}/teacher-assignments", [
            'school_class_id' => $this->classB->id, 'subject' => 'ისტორია',
        ])->assertSessionHasErrors('school_class_id');

        $this->actingAs($this->admin)->delete("/portal/members/teacher-assignments/{$assignment->id}")
            ->assertRedirect(route('members.index'));

        $this->assertDatabaseMissing('teacher_assignments', ['id' => $assignment->id]);
        $this->assertDatabaseHas('audit_events', ['action' => 'teacher_assignment.removed', 'subject_id' => $assignment->id]);
    }

    public function test_assignment_with_dependent_records_cannot_be_removed(): void
    {
        $teacher = User::factory()->create();
        $this->membership($teacher, TenantMembership::ROLE_TEACHER);
        $assignment = TeacherAssignment::forceCreate([
            'tenant_id' => $this->tenant->id, 'user_id' => $teacher->id, 'school_class_id' => $this->classA->id, 'subject' => 'მათემატიკა',
        ]);

        DB::table('assignments')->insert([
            'tenant_id' => $this->tenant->id,
            'teacher_assignment_id' => $assignment->id,
            'title' => 'სატესტო დავალება',
            'status' => 'published',
            'created_by' => $teacher->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin)->delete("/portal/members/teacher-assignments/{$assignment->id}")
            ->assertSessionHasErrors('assignment');

        $this->assertDatabaseHas('teacher_assignments', ['id' => $assignment->id]);
        $this->assertDatabaseCount('assignments', 1);
    }

    public function test_admin_edits_transfers_and_deactivates_student(): void
    {
        $this->actingAs($this->admin)->put("/portal/academic-structure/students/{$this->student->id}", [
            'school_class_id' => $this->classB->id,
            'first_name' => 'შესწორებული',
            'last_name' => 'მოსწავლე',
            'national_id' => '01001010101',
            'is_active' => '1',
        ])->assertRedirect(route('academic-structure.index'));

        $this->student->refresh();
        $this->assertSame($this->classB->id, $this->student->school_class_id);
        $this->assertSame('შესწორებული', $this->student->first_name);
        $this->assertDatabaseHas('audit_events', ['action' => 'student.transferred', 'subject_id' => $this->student->id]);

        // National id is never written into the audit log.
        $this->assertDatabaseMissing('audit_events', ['meta' => json_encode(['national_id' => '01001010101'])]);
        $this->assertStringNotContainsString('01001010101', (string) DB::table('audit_events')->pluck('meta')->implode(' '));

        $this->actingAs($this->admin)->put("/portal/academic-structure/students/{$this->student->id}", [
            'school_class_id' => $this->classB->id,
            'first_name' => 'შესწორებული',
            'last_name' => 'მოსწავლე',
            'national_id' => '01001010101',
            'is_active' => '0',
        ])->assertRedirect(route('academic-structure.index'));

        $this->assertFalse($this->student->fresh()->is_active);
        $this->assertDatabaseHas('audit_events', ['action' => 'student.deactivated', 'subject_id' => $this->student->id]);
    }

    public function test_student_edit_rejects_duplicate_national_id_and_foreign_class(): void
    {
        $this->sibling->update(['national_id' => '11111111111']);

        $this->actingAs($this->admin)->put("/portal/academic-structure/students/{$this->student->id}", [
            'school_class_id' => $this->classA->id, 'first_name' => 'A', 'last_name' => 'B', 'national_id' => '11111111111', 'is_active' => '1',
        ])->assertSessionHasErrors('national_id');

        $tenantB = $this->otherTenant();
        $yearB = $tenantB->academicYears()->create(['name' => 'B', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-15', 'is_current' => true]);
        $foreignClass = $tenantB->schoolClasses()->create(['academic_year_id' => $yearB->id, 'name' => 'B']);

        $this->actingAs($this->admin)->put("/portal/academic-structure/students/{$this->student->id}", [
            'school_class_id' => $foreignClass->id, 'first_name' => 'A', 'last_name' => 'B', 'is_active' => '1',
        ])->assertNotFound();

        $this->assertSame($this->classA->id, $this->student->fresh()->school_class_id);
    }

    public function test_admin_resends_expired_invitation_with_new_token(): void
    {
        Mail::fake();
        $invitation = TenantInvitation::forceCreate([
            'tenant_id' => $this->tenant->id,
            'email' => 'slow@example.test',
            'role' => TenantMembership::ROLE_TEACHER,
            'token' => TenantInvitation::generateToken(),
            'expires_at' => Carbon::now()->subDay(),
            'invited_by' => $this->admin->id,
        ]);
        $oldToken = $invitation->token;

        $this->actingAs($this->admin)->post("/portal/members/invitations/{$invitation->id}/resend")
            ->assertRedirect(route('members.index'));

        $invitation->refresh();
        $this->assertNotSame($oldToken, $invitation->token);
        $this->assertTrue($invitation->expires_at->isFuture());
        Mail::assertSent(TenantInvitationMail::class, fn (TenantInvitationMail $mail) => $mail->hasTo('slow@example.test'));
        $this->assertDatabaseHas('audit_events', ['action' => 'invitation.resent', 'subject_id' => $invitation->id]);

        // The old link no longer works.
        auth()->logout();
        $this->get("/invitations/{$oldToken}")->assertNotFound();
    }

    public function test_members_screen_lists_expired_invitations_and_link_details(): void
    {
        $guardian = User::factory()->create();
        $this->membership($guardian, TenantMembership::ROLE_GUARDIAN);
        GuardianLink::forceCreate([
            'tenant_id' => $this->tenant->id, 'user_id' => $guardian->id, 'student_id' => $this->student->id,
            'can_view_academic' => true, 'can_view_financial' => true, 'can_pickup' => false, 'can_receive_notifications' => true, 'is_active' => false,
        ]);
        TenantInvitation::forceCreate([
            'tenant_id' => $this->tenant->id, 'email' => 'expired@example.test', 'role' => TenantMembership::ROLE_GUARDIAN,
            'token' => TenantInvitation::generateToken(), 'expires_at' => Carbon::now()->subDay(), 'invited_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)->get('/portal/members')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('portal/members/index')
                ->where('pendingInvitations.0.email', 'expired@example.test')
                ->where('pendingInvitations.0.isExpired', true)
                ->has('members', fn ($members) => $members->each(fn ($member) => $member->etc()))
            );

        $members = $this->actingAs($this->admin)->get('/portal/members')->viewData('page')['props']['members'];
        $guardianRow = collect($members)->firstWhere('userEmail', $guardian->email);
        $this->assertFalse($guardianRow['guardianLinks'][0]['isActive']);
        $this->assertTrue($guardianRow['guardianLinks'][0]['canViewFinancial']);
    }
}
