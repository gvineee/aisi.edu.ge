<?php

namespace Tests\Feature\Portal;

use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Calendar\Models\SchoolEvent;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Timetable\Models\NonTeachingPeriod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The school calendar: management adds events for everyone, for staff or
 * for classes; each person sees only what is meant for them.
 */
class SchoolCalendarTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_role_sees_only_its_events_and_only_management_edits(): void
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->firstOrFail()->tenant;
        $year = $tenant->academicYears()->create(['name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30', 'is_current' => true]);
        $a = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => '1ა']);
        $b = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => '2ა']);

        [$manager, $teacher, $parent, $pupil] = array_map(function (string $role) use ($tenant) {
            $user = User::factory()->create();
            TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role, 'is_active' => true]);

            return $user;
        }, [TenantMembership::ROLE_ACADEMIC_MANAGER, TenantMembership::ROLE_TEACHER, TenantMembership::ROLE_GUARDIAN, TenantMembership::ROLE_STUDENT]);

        $child = $tenant->students()->create(['school_class_id' => $a->id, 'first_name' => 'ანა', 'last_name' => 'ტესტი', 'is_active' => true]);
        $tenant->students()->create(['school_class_id' => $b->id, 'first_name' => 'ბექა', 'last_name' => 'ტესტი', 'is_active' => true, 'user_id' => $pupil->id]);
        $link = new GuardianLink(['user_id' => $parent->id, 'student_id' => $child->id, 'can_view_academic' => true, 'can_view_financial' => false, 'can_pickup' => false, 'can_receive_notifications' => true, 'is_active' => true]);
        $link->tenant_id = $tenant->id;
        $link->save();
        $break = new NonTeachingPeriod(['academic_year_id' => $year->id, 'label' => 'არდადეგები', 'starts_on' => '2026-11-02', 'ends_on' => '2026-11-04']);
        $break->tenant_id = $tenant->id;
        $break->save();

        $post = fn (array $data) => $this->actingAs($manager)->post('/portal/calendar/events', $data + ['category' => 'event', 'starts_on' => '2026-11-10']);
        $post(['title' => 'სკოლის დღე', 'audience' => 'everyone', 'starts_at' => '11:00', 'ends_at' => '13:00'])->assertSessionHasNoErrors();
        $post(['title' => 'პედსაბჭო', 'audience' => 'staff', 'category' => 'meeting'])->assertSessionHasNoErrors();
        $post(['title' => 'ტესტი', 'audience' => 'classes', 'category' => 'exam', 'class_ids' => [$a->id]])->assertSessionHasNoErrors();
        $post(['title' => 'ექსკურსია', 'audience' => 'classes', 'category' => 'trip', 'class_ids' => [$b->id], 'starts_on' => '2026-11-20', 'ends_on' => '2026-11-21'])->assertSessionHasNoErrors();

        $this->assertSame($year->id, SchoolEvent::query()->where('title', 'სკოლის დღე')->value('academic_year_id'));
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->id, 'action' => 'calendar.event_created']);

        $titles = fn (User $user, string $month = '2026-11') => collect($this->actingAs($user)->get("/portal/calendar?month={$month}")->assertOk()->viewData('page')['props']['entries'])->pluck('title')->all();

        // All-day entries come before the timed ones of the same day.
        $this->assertSame(['არდადეგები', 'პედსაბჭო', 'ტესტი', 'სკოლის დღე', 'ექსკურსია'], $titles($teacher));
        $this->assertSame(['არდადეგები', 'ტესტი', 'სკოლის დღე'], $titles($parent));
        $this->assertSame(['არდადეგები', 'სკოლის დღე', 'ექსკურსია'], $titles($pupil));
        $this->assertSame([], $titles($teacher, '2026-12'));

        // A revoked link hides the child's class events again.
        $link->update(['is_active' => false]);
        $this->assertSame(['არდადეგები', 'სკოლის დღე'], $titles($parent));

        // Only management writes; a class of another school is refused.
        $this->actingAs($teacher)->post('/portal/calendar/events', ['title' => 'x', 'category' => 'event', 'starts_on' => '2026-11-10', 'audience' => 'everyone'])->assertForbidden();
        $this->actingAs($parent)->get('/portal/calendar')->assertInertia(fn ($page) => $page->where('canManage', false)->where('classes', []));
        $other = Tenant::create(['slug' => 'other-school', 'name' => 'Other', 'locale' => 'ka', 'timezone' => 'Asia/Tbilisi', 'is_active' => true]);
        $otherYear = $other->academicYears()->create(['name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30', 'is_current' => true]);
        $foreign = $other->schoolClasses()->create(['academic_year_id' => $otherYear->id, 'name' => '1ა']);
        $post(['title' => 'უცხო', 'audience' => 'classes', 'class_ids' => [$foreign->id]])->assertSessionHasErrors('class_ids.0');
        $post(['title' => 'კლასის გარეშე', 'audience' => 'classes'])->assertSessionHasErrors('class_ids');
        $post(['title' => 'უკუღმა', 'audience' => 'everyone', 'starts_at' => '12:00', 'ends_at' => '10:00'])->assertSessionHasErrors('ends_at');

        $event = SchoolEvent::query()->where('title', 'ტესტი')->firstOrFail();
        $this->actingAs($manager)->put("/portal/calendar/events/{$event->id}", ['title' => 'ტესტი', 'category' => 'exam', 'starts_on' => '2026-11-12', 'audience' => 'classes', 'class_ids' => [$b->id]])->assertSessionHasNoErrors();
        $this->assertSame([$b->id], $event->fresh()->classes->pluck('id')->all());
        $this->assertContains('ტესტი', $titles($pupil));

        $this->actingAs($teacher)->delete("/portal/calendar/events/{$event->id}")->assertForbidden();
        $this->actingAs($manager)->delete("/portal/calendar/events/{$event->id}")->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('school_events', ['id' => $event->id]);
        $this->assertDatabaseMissing('school_event_classes', ['school_event_id' => $event->id]);
    }
}
