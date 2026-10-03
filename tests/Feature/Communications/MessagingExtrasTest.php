<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Models\ActionRequest;
use App\Domain\Communications\Models\Conversation;
use App\Domain\Communications\Models\Message;
use App\Domain\Communications\Models\MessageDelivery;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Jobs\SendMessageEmailNotification;
use App\Mail\NewMessageNotificationMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * CLAUDE-PLATFORM-MODULES §4: personal archive and search, needs-response
 * requests that close on reply/acknowledge and surface in the day centre,
 * opt-in email notification sent once without the message body, and read
 * receipts only for the sender.
 */
class MessagingExtrasTest extends TestCase
{
    use RefreshDatabase;

    public function test_archive_search_action_requests_email_and_receipts(): void
    {
        Mail::fake();
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;
        $year = $tenant->academicYears()->create(['name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-15', 'is_current' => true]);
        $class = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => 'VI']);
        $student = $tenant->students()->create(['school_class_id' => $class->id, 'first_name' => 'ნიკა', 'last_name' => 'დ.', 'is_active' => true]);

        $guardian = User::factory()->create();
        $teacher = User::factory()->create();
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $guardian->id, 'role' => TenantMembership::ROLE_GUARDIAN, 'is_active' => true]);
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $teacher->id, 'role' => TenantMembership::ROLE_TEACHER, 'is_active' => true]);
        $tenant->guardianLinks()->create(['user_id' => $guardian->id, 'student_id' => $student->id, 'can_view_academic' => true, 'can_view_financial' => false, 'can_pickup' => true, 'can_receive_notifications' => true, 'is_active' => true]);
        $tenant->teacherAssignments()->create(['user_id' => $teacher->id, 'school_class_id' => $class->id, 'subject' => 'ქართული ენა']);

        // The teacher opts in to email notifications.
        $this->actingAs($teacher)->post('/portal/messages-preferences', ['email' => true])->assertRedirect();

        $this->actingAs($guardian)->post('/portal/messages', ['recipient_id' => $teacher->id, 'subject' => 'ექსკურსიის შესახებ', 'body' => 'ჯანმრთელობის საიდუმლო დეტალი']);
        $conversation = Conversation::query()->firstOrFail();
        $message = Message::query()->firstOrFail();

        // One email, sent once, and without the message body.
        Mail::assertSent(NewMessageNotificationMail::class, 1);
        Mail::assertSent(NewMessageNotificationMail::class, fn (NewMessageNotificationMail $mail) => ! str_contains((string) $mail->content()->htmlString, 'საიდუმლო'));
        $this->assertSame('sent', MessageDelivery::query()->where('channel', 'email')->value('status'));
        (new SendMessageEmailNotification((int) MessageDelivery::query()->where('channel', 'email')->value('id'), 'x', 'y', 'z'))->handle();
        Mail::assertSent(NewMessageNotificationMail::class, 1);

        // The teacher opens the thread (read receipt); the guardian (no opt-in) gets no email on the reply.
        $this->actingAs($teacher)->get("/portal/messages/{$conversation->id}")->assertOk();
        $this->actingAs($teacher)->post("/portal/messages/{$conversation->id}/reply", ['body' => 'კარგი']);
        Mail::assertSent(NewMessageNotificationMail::class, 1);

        // Needs response: only the sender may request; it appears in the target's day centre.
        $this->actingAs($teacher)->post("/portal/messages/{$conversation->id}/messages/{$message->id}/request-response", ['type' => 'acknowledge'])->assertForbidden();
        $this->actingAs($guardian)->post("/portal/messages/{$conversation->id}/messages/{$message->id}/request-response", ['type' => 'reply'])->assertRedirect();
        $request = ActionRequest::query()->firstOrFail();
        $this->assertSame($teacher->id, $request->target_user_id);
        $feed = $this->actingAs($teacher)->get('/portal/today')->viewData('page')['props']['actionItems'];
        $this->assertContains('action_request', array_column($feed, 'type'));

        // Replying closes it.
        $this->actingAs($teacher)->post("/portal/messages/{$conversation->id}/reply", ['body' => 'პასუხი']);
        $this->assertSame('done', $request->fresh()->status);

        // Read receipts only on the sender's own messages.
        $this->actingAs($guardian)->get("/portal/messages/{$conversation->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('activeConversation.messages.0.delivery.recipients', 1)
                ->where('activeConversation.messages.0.delivery.read', 1)
                ->where('activeConversation.messages.1.delivery', null));

        // Search and personal archive.
        $this->actingAs($guardian)->get('/portal/messages?q=ექსკურსი')->assertInertia(fn (Assert $page) => $page->has('conversations', 1));
        $this->actingAs($guardian)->get('/portal/messages?q=არარსებული')->assertInertia(fn (Assert $page) => $page->has('conversations', 0));
        $this->actingAs($guardian)->post("/portal/messages/{$conversation->id}/archive", ['archived' => true]);
        $this->actingAs($guardian)->get('/portal/messages')->assertInertia(fn (Assert $page) => $page->has('conversations', 0));
        $this->actingAs($guardian)->get('/portal/messages?archived=1')->assertInertia(fn (Assert $page) => $page->has('conversations', 1));
        $this->actingAs($teacher)->get('/portal/messages')->assertInertia(fn (Assert $page) => $page->has('conversations', 1));
    }
}
