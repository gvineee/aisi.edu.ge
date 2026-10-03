<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Actions\StartConversation;
use App\Domain\Communications\Models\ConversationParticipant;
use App\Domain\Communications\Models\MessageBroadcast;
use App\Domain\Communications\Models\MessageBroadcastRecipient;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Jobs\DeliverMessageBroadcast;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * CLAUDE-PLATFORM-MODULES §4: class announcement recipients are resolved
 * on the server and snapshotted; each family gets its own conversation;
 * re-running the job sends nothing twice; only assigned teachers or
 * leadership may send.
 */
class ClassBroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_class_broadcast_resolves_snapshots_and_delivers_once_per_family(): void
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;
        $year = $tenant->academicYears()->create(['name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-15', 'is_current' => true]);
        $class = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => 'VI']);
        $otherClass = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => 'VII']);

        $member = function (string $role) use ($tenant): User {
            $user = User::factory()->create();
            TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role, 'is_active' => true]);

            return $user;
        };
        $teacher = $member(TenantMembership::ROLE_TEACHER);
        $outsider = $member(TenantMembership::ROLE_TEACHER);
        $g1 = $member(TenantMembership::ROLE_GUARDIAN);
        $g2 = $member(TenantMembership::ROLE_GUARDIAN);
        $optedOut = $member(TenantMembership::ROLE_GUARDIAN);
        $otherClassGuardian = $member(TenantMembership::ROLE_GUARDIAN);
        $tenant->teacherAssignments()->create(['user_id' => $teacher->id, 'school_class_id' => $class->id, 'subject' => 'ქართული ენა']);

        $kid = fn (int $classId, string $name) => $tenant->students()->create(['school_class_id' => $classId, 'first_name' => $name, 'last_name' => 'ტ.', 'is_active' => true]);
        $a = $kid($class->id, 'ა');
        $b = $kid($class->id, 'ბ');
        $c = $kid($class->id, 'გ');
        $d = $kid($otherClass->id, 'დ');
        $link = fn (User $g, int $studentId, bool $notify) => $tenant->guardianLinks()->create(['user_id' => $g->id, 'student_id' => $studentId, 'can_view_academic' => true,
            'can_view_financial' => false, 'can_pickup' => false, 'can_receive_notifications' => $notify, 'is_active' => true]);
        $link($g1, $a->id, true);
        $link($g1, $b->id, true); // two children in the class → one message
        $link($g2, $b->id, true);
        $link($optedOut, $c->id, false);
        $link($otherClassGuardian, $d->id, true);

        $this->actingAs($teacher)->get('/portal/messages')->assertInertia(fn (Assert $page) => $page->has('broadcastClasses', 1)->where('broadcastClasses.0.id', $class->id));
        $this->actingAs($outsider)->post('/portal/messages-broadcasts', ['school_class_id' => $class->id, 'subject' => 'x', 'body' => 'y'])->assertForbidden();
        $this->actingAs($g1)->post('/portal/messages-broadcasts', ['school_class_id' => $class->id, 'subject' => 'x', 'body' => 'y'])->assertForbidden();

        $this->actingAs($teacher)->post('/portal/messages-broadcasts', ['school_class_id' => $class->id, 'subject' => 'ექსკურსია პარასკევს', 'body' => 'გთხოვთ, მოამზადოთ თანხმობა.'])
            ->assertSessionHasNoErrors();

        $broadcast = MessageBroadcast::query()->firstOrFail();
        $this->assertSame(2, $broadcast->recipients_count);
        $this->assertEqualsCanonicalizing([$g1->id, $g2->id], MessageBroadcastRecipient::query()->pluck('user_id')->all());
        $this->assertSame(MessageBroadcast::STATUS_SENT, $broadcast->fresh()->status);
        $this->assertSame(2, $broadcast->fresh()->delivered_count);

        // Each family has its own two-person conversation.
        foreach (MessageBroadcastRecipient::query()->get() as $recipient) {
            $this->assertEqualsCanonicalizing([$teacher->id, $recipient->user_id],
                ConversationParticipant::query()->where('conversation_id', $recipient->conversation_id)->pluck('user_id')->all());
        }
        $this->actingAs($otherClassGuardian)->get('/portal/messages')->assertInertia(fn (Assert $page) => $page->has('conversations', 0));
        $this->actingAs($optedOut)->get('/portal/messages')->assertInertia(fn (Assert $page) => $page->has('conversations', 0));

        // A retried job delivers nothing twice.
        (new DeliverMessageBroadcast($broadcast->id))->handle(app(StartConversation::class), app(CurrentTenant::class));
        $this->assertSame(2, ConversationParticipant::query()->where('user_id', $teacher->id)->count());

        $this->actingAs($teacher)->get('/portal/messages')->assertInertia(fn (Assert $page) => $page->where('recentBroadcasts.0.delivered', 2));
    }
}
