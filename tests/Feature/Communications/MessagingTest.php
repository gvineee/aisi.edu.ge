<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Models\Conversation;
use App\Domain\Communications\Models\ConversationParticipant;
use App\Domain\Communications\Models\MessageDelivery;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers CLAUDE-PLATFORM-MODULES.md §4/§17's required cases: a user can only
 * reach someone they have a real relationship with (never an open "any
 * tenant user" picker), tenant isolation, and idempotent recipient
 * resolution — never a client-picked, unchecked recipient id.
 */
class MessagingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{tenant: Tenant, guardian: User, teacher: User, otherTeacher: User, admin: User}
     */
    private function baseFixtures(): array
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;

        $year = $tenant->academicYears()->create([
            'name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-15', 'is_current' => true,
        ]);
        $class = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => 'VI']);
        $otherClass = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => 'VII']);

        $student = $tenant->students()->create(['school_class_id' => $class->id, 'first_name' => 'ნიკა', 'last_name' => 'დ.', 'is_active' => true]);

        $guardian = User::factory()->create();
        $teacher = User::factory()->create();
        $otherTeacher = User::factory()->create();
        $admin = User::factory()->create();

        foreach ([
            [$guardian, TenantMembership::ROLE_GUARDIAN],
            [$teacher, TenantMembership::ROLE_TEACHER],
            [$otherTeacher, TenantMembership::ROLE_TEACHER],
            [$admin, TenantMembership::ROLE_ADMIN],
        ] as [$user, $role]) {
            TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role, 'is_active' => true]);
        }

        $tenant->guardianLinks()->create([
            'user_id' => $guardian->id, 'student_id' => $student->id,
            'can_view_academic' => true, 'can_view_financial' => true,
            'can_pickup' => true, 'can_receive_notifications' => true, 'is_active' => true,
        ]);

        $tenant->teacherAssignments()->create(['user_id' => $teacher->id, 'school_class_id' => $class->id, 'subject' => 'ქართული ენა']);
        $tenant->teacherAssignments()->create(['user_id' => $otherTeacher->id, 'school_class_id' => $otherClass->id, 'subject' => 'მათემატიკა']);

        return compact('tenant', 'guardian', 'teacher', 'otherTeacher', 'admin');
    }

    public function test_guardian_can_start_conversation_with_their_childs_teacher(): void
    {
        ['guardian' => $guardian, 'teacher' => $teacher] = $this->baseFixtures();

        $response = $this->actingAs($guardian)->post('/portal/messages', [
            'recipient_id' => $teacher->id,
            'subject' => 'კითხვა ნიკას შესახებ',
            'body' => 'გამარჯობა, გვინდა ვისაუბროთ.',
        ]);

        $conversation = Conversation::query()->first();
        $response->assertRedirect(route('messages.show', $conversation));

        $this->assertSame(2, ConversationParticipant::query()->where('conversation_id', $conversation->id)->count());
        $this->assertSame(1, MessageDelivery::query()->where('recipient_id', $teacher->id)->count());
    }

    public function test_guardian_cannot_message_unrelated_teacher(): void
    {
        ['guardian' => $guardian, 'otherTeacher' => $otherTeacher] = $this->baseFixtures();

        $response = $this->actingAs($guardian)->post('/portal/messages', [
            'recipient_id' => $otherTeacher->id,
            'subject' => 'თემა',
            'body' => 'ტექსტი',
        ]);

        $response->assertForbidden();
        $this->assertSame(0, Conversation::query()->count());
    }

    public function test_guardian_cannot_message_another_guardian(): void
    {
        ['tenant' => $tenant, 'guardian' => $guardian] = $this->baseFixtures();

        $otherGuardian = User::factory()->create();
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $otherGuardian->id, 'role' => TenantMembership::ROLE_GUARDIAN, 'is_active' => true]);

        $response = $this->actingAs($guardian)->post('/portal/messages', [
            'recipient_id' => $otherGuardian->id,
            'subject' => 'თემა',
            'body' => 'ტექსტი',
        ]);

        $response->assertForbidden();
    }

    public function test_staff_members_can_message_each_other(): void
    {
        ['teacher' => $teacher, 'admin' => $admin] = $this->baseFixtures();

        $response = $this->actingAs($teacher)->post('/portal/messages', [
            'recipient_id' => $admin->id,
            'subject' => 'ადმინისტრაციული საკითხი',
            'body' => 'გამარჯობა',
        ]);

        $conversation = Conversation::query()->first();
        $response->assertRedirect(route('messages.show', $conversation));
    }

    public function test_reply_creates_message_and_delivery_for_other_participant_only(): void
    {
        ['guardian' => $guardian, 'teacher' => $teacher] = $this->baseFixtures();

        $this->actingAs($guardian)->post('/portal/messages', [
            'recipient_id' => $teacher->id, 'subject' => 'თემა', 'body' => 'პირველი',
        ]);
        $conversation = Conversation::query()->first();

        $this->actingAs($teacher)->post("/portal/messages/{$conversation->id}/reply", ['body' => 'პასუხი']);

        $this->assertSame(2, $conversation->messages()->count());
        // One delivery for the guardian from the teacher's reply, and the
        // original one for the teacher from the guardian's first message —
        // but never a self-delivery for whoever is the current sender.
        $this->assertSame(1, MessageDelivery::query()->where('recipient_id', $guardian->id)->count());
        $this->assertSame(1, MessageDelivery::query()->where('recipient_id', $teacher->id)->count());
        $this->assertSame(2, MessageDelivery::query()->count());
    }

    public function test_non_participant_cannot_reply(): void
    {
        ['guardian' => $guardian, 'teacher' => $teacher, 'otherTeacher' => $otherTeacher] = $this->baseFixtures();

        $this->actingAs($guardian)->post('/portal/messages', [
            'recipient_id' => $teacher->id, 'subject' => 'თემა', 'body' => 'პირველი',
        ]);
        $conversation = Conversation::query()->first();

        $response = $this->actingAs($otherTeacher)->post("/portal/messages/{$conversation->id}/reply", ['body' => 'ვცდი']);

        $response->assertForbidden();
    }

    public function test_viewing_conversation_marks_it_read(): void
    {
        ['guardian' => $guardian, 'teacher' => $teacher] = $this->baseFixtures();

        $this->actingAs($guardian)->post('/portal/messages', [
            'recipient_id' => $teacher->id, 'subject' => 'თემა', 'body' => 'პირველი',
        ]);
        $conversation = Conversation::query()->first();

        $delivery = MessageDelivery::query()->where('recipient_id', $teacher->id)->first();
        $this->assertNull($delivery->read_at);

        $this->actingAs($teacher)->get("/portal/messages/{$conversation->id}")->assertOk();

        $this->assertNotNull($delivery->fresh()->read_at);
    }

    public function test_tenant_isolation_on_conversation_show(): void
    {
        ['guardian' => $guardian, 'teacher' => $teacher] = $this->baseFixtures();

        $this->actingAs($guardian)->post('/portal/messages', [
            'recipient_id' => $teacher->id, 'subject' => 'თემა', 'body' => 'პირველი',
        ]);
        $conversation = Conversation::query()->first();

        $otherTenant = Tenant::create(['slug' => 'other-school', 'name' => 'Other', 'locale' => 'ka', 'timezone' => 'Asia/Tbilisi', 'is_active' => true]);
        TenantDomain::create(['tenant_id' => $otherTenant->id, 'domain' => 'other-school.test', 'is_primary' => true]);

        $outsider = User::factory()->create();
        TenantMembership::create(['tenant_id' => $otherTenant->id, 'user_id' => $outsider->id, 'role' => TenantMembership::ROLE_ADMIN, 'is_active' => true]);

        $response = $this->actingAs($outsider)
            ->get("http://other-school.test/portal/messages/{$conversation->id}", ['Host' => 'other-school.test']);

        $response->assertNotFound();
    }
}
