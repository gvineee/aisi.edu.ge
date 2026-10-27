<?php

namespace Tests\Feature\Timetable;

use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Timetable\Models\StaffTimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Clock-in: one open entry at a time, a forgotten clock-out waits for a
 * correction, management corrects with a reason and sees the month.
 */
class StaffTimeClockTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_clock_in_and_out_and_management_corrects(): void
    {
        $this->travelTo(Carbon::parse('2026-11-05 08:30', 'Asia/Tbilisi'));
        $tenant = TenantDomain::query()->where('domain', 'localhost')->firstOrFail()->tenant;
        [$accountant, $teacher, $parent] = array_map(function (string $role) use ($tenant) {
            $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
            TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role, 'is_active' => true]);

            return $user;
        }, [TenantMembership::ROLE_ACCOUNTANT, TenantMembership::ROLE_TEACHER, TenantMembership::ROLE_GUARDIAN]);

        $this->actingAs($parent)->post('/portal/time-clock/in')->assertForbidden();
        $this->actingAs($teacher)->post('/portal/time-clock/out')->assertSessionHasErrors('clock');
        $this->actingAs($teacher)->post('/portal/time-clock/in')->assertSessionHasNoErrors();
        $this->actingAs($teacher)->post('/portal/time-clock/in')->assertSessionHasErrors('clock');

        $this->travelTo(Carbon::parse('2026-11-05 15:00', 'Asia/Tbilisi'));
        $this->actingAs($teacher)->post('/portal/time-clock/out')->assertSessionHasNoErrors();
        $this->assertSame(390, StaffTimeEntry::query()->sole()->minutes());
        $this->assertSame('2026-11-05', StaffTimeEntry::query()->sole()->work_date->toDateString());

        // Next day: in, forgets to leave; the day after, a new arrival is still possible.
        $this->travelTo(Carbon::parse('2026-11-06 08:40', 'Asia/Tbilisi'));
        $this->actingAs($teacher)->post('/portal/time-clock/in')->assertSessionHasNoErrors();
        $this->travelTo(Carbon::parse('2026-11-09 08:35', 'Asia/Tbilisi'));
        $this->actingAs($teacher)->get('/portal/time-clock')->assertInertia(fn ($page) => $page->where('open', null)->where('isManager', false)->where('summary', []));
        $this->actingAs($teacher)->post('/portal/time-clock/in')->assertSessionHasNoErrors();

        $this->actingAs($accountant)->get("/portal/time-clock?month=2026-11&user={$teacher->id}")->assertInertia(fn ($page) => $page
            ->where('isManager', true)->where('summary.0.days', 3)->where('summary.0.minutes', 390)->where('summary.0.open', 2)
            ->has('selected.entries', 3));

        $forgotten = StaffTimeEntry::query()->whereDate('work_date', '2026-11-06')->sole();
        $this->actingAs($teacher)->put("/portal/time-clock/entries/{$forgotten->id}", ['clock_in_at' => '2026-11-06T08:40', 'clock_out_at' => '2026-11-06T14:40', 'reason' => 'x'])->assertForbidden();
        $this->actingAs($accountant)->put("/portal/time-clock/entries/{$forgotten->id}", ['clock_in_at' => '2026-11-06T08:40', 'clock_out_at' => '2026-11-06T08:00', 'reason' => 'x'])->assertSessionHasErrors('clock_out_at');
        $this->actingAs($accountant)->put("/portal/time-clock/entries/{$forgotten->id}", ['clock_in_at' => '2026-11-06T08:40', 'clock_out_at' => '2026-11-06T14:40', 'reason' => 'დაავიწყდა'])->assertSessionHasNoErrors();
        $this->assertSame(360, $forgotten->fresh()->minutes());
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->id, 'action' => 'time_clock.entry_corrected']);

        $this->actingAs($accountant)->post('/portal/time-clock/entries', ['user_id' => $teacher->id, 'clock_in_at' => '2026-11-04T09:00', 'clock_out_at' => '2026-11-04T13:00', 'reason' => 'ქაღალდის ჟურნალიდან'])->assertSessionHasNoErrors();
        $this->actingAs($accountant)->post('/portal/time-clock/entries', ['user_id' => $parent->id, 'clock_in_at' => '2026-11-04T09:00', 'reason' => 'x'])->assertSessionHasErrors('user_id');
        $this->actingAs($accountant)->get('/portal/time-clock?month=2026-11')->assertInertia(fn ($page) => $page->where('summary.0.minutes', 390 + 360 + 240));

        $this->actingAs($accountant)->get('/portal/time-clock/export?month=2026-11')->assertOk();
        $this->actingAs($teacher)->get('/portal/time-clock/export?month=2026-11')->assertForbidden();
    }
}
