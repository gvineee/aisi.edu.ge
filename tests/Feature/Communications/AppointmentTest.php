<?php

namespace Tests\Feature\Communications;

use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Academics\Models\Student;
use App\Domain\Communications\Models\AppointmentBooking;
use App\Domain\Communications\Models\AppointmentSlot;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Parent–teacher meetings: guardians book only with their own child's
 * teachers, capacity is enforced (no overbooking when two guardians race
 * for the last place), owners/teachers alone may cancel, tenant isolation.
 */
class AppointmentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function fixtures(): array
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;
        $year = $tenant->academicYears()->create(['name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-15', 'is_current' => true]);
        $class = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => 'VI']);
        $otherClass = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => 'VII']);

        $u = [];
        foreach (['teacher' => TenantMembership::ROLE_TEACHER, 'otherTeacher' => TenantMembership::ROLE_TEACHER, 'guardianA' => TenantMembership::ROLE_GUARDIAN, 'guardianB' => TenantMembership::ROLE_GUARDIAN] as $key => $role) {
            $u[$key] = User::factory()->create();
            TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $u[$key]->id, 'role' => $role, 'is_active' => true]);
        }

        $tenant->teacherAssignments()->create(['user_id' => $u['teacher']->id, 'school_class_id' => $class->id, 'subject' => 'მათემატიკა']);
        $tenant->teacherAssignments()->create(['user_id' => $u['otherTeacher']->id, 'school_class_id' => $otherClass->id, 'subject' => 'ისტორია']);

        $childA = $tenant->students()->create(['school_class_id' => $class->id, 'first_name' => 'ნიკა', 'last_name' => 'დ.', 'is_active' => true]);
        $childB = $tenant->students()->create(['school_class_id' => $class->id, 'first_name' => 'ანა', 'last_name' => 'ბ.', 'is_active' => true]);

        $this->link($tenant, $u['guardianA'], $childA);
        $this->link($tenant, $u['guardianB'], $childB);

        return $u + compact('tenant', 'childA', 'childB');
    }

    private function link(Tenant $tenant, User $guardian, Student $student): void
    {
        $link = new GuardianLink(['user_id' => $guardian->id, 'student_id' => $student->id, 'can_view_academic' => true, 'can_view_financial' => false, 'can_pickup' => true, 'can_receive_notifications' => true, 'is_active' => true]);
        $link->tenant_id = $tenant->id;
        $link->save();
    }

    private function createSlot(User $teacher, int $capacity = 1, string $offset = '+1 day'): AppointmentSlot
    {
        $this->actingAs($teacher)->post('/portal/appointments/slots', [
            'starts_at' => now('Asia/Tbilisi')->modify($offset)->format('Y-m-d\TH:i'),
            'duration_minutes' => 15,
            'location' => 'ოთახი 12',
            'capacity' => $capacity,
        ])->assertSessionHasNoErrors();

        return AppointmentSlot::query()->latest('id')->firstOrFail();
    }

    public function test_guardian_books_with_own_childs_teacher_and_capacity_holds(): void
    {
        $f = $this->fixtures();
        $slot = $this->createSlot($f['teacher']);

        $this->actingAs($f['guardianA'])->get('/portal/appointments')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('children.0.slots.0.id', $slot->id));

        $this->actingAs($f['guardianA'])->post("/portal/appointments/slots/{$slot->id}/book", ['student_id' => $f['childA']->id])->assertSessionHasNoErrors();

        // The last place is taken: the second guardian is rejected, no overbooking.
        $this->actingAs($f['guardianB'])->post("/portal/appointments/slots/{$slot->id}/book", ['student_id' => $f['childB']->id])
            ->assertSessionHasErrors('appointment_slot_id');
        $this->assertSame(1, AppointmentBooking::query()->where('status', 'booked')->count());

        // The same child cannot book the same slot twice either.
        $bigSlot = $this->createSlot($f['teacher'], 3, '+2 days');
        $this->actingAs($f['guardianA'])->post("/portal/appointments/slots/{$bigSlot->id}/book", ['student_id' => $f['childA']->id])->assertSessionHasNoErrors();
        $this->actingAs($f['guardianA'])->post("/portal/appointments/slots/{$bigSlot->id}/book", ['student_id' => $f['childA']->id])->assertSessionHasErrors('appointment_slot_id');
    }

    public function test_guardian_cannot_book_unrelated_teacher_or_for_another_child(): void
    {
        $f = $this->fixtures();
        $foreignSlot = $this->createSlot($f['otherTeacher']);
        $slot = $this->createSlot($f['teacher'], 1, '+3 days');

        $this->actingAs($f['guardianA'])->post("/portal/appointments/slots/{$foreignSlot->id}/book", ['student_id' => $f['childA']->id])
            ->assertSessionHasErrors('appointment_slot_id');
        $this->actingAs($f['guardianA'])->post("/portal/appointments/slots/{$slot->id}/book", ['student_id' => $f['childB']->id])
            ->assertForbidden();

        $this->assertSame(0, AppointmentBooking::query()->count());
    }

    public function test_slot_rules_and_cancellation_rights(): void
    {
        $f = $this->fixtures();

        $this->actingAs($f['teacher'])->post('/portal/appointments/slots', [
            'starts_at' => now('Asia/Tbilisi')->subDay()->format('Y-m-d\TH:i'), 'duration_minutes' => 15,
        ])->assertSessionHasErrors('starts_at');

        $slot = $this->createSlot($f['teacher']);
        $this->actingAs($f['teacher'])->post('/portal/appointments/slots', [
            'starts_at' => $slot->starts_at->copy()->setTimezone('Asia/Tbilisi')->addMinutes(5)->format('Y-m-d\TH:i'), 'duration_minutes' => 15,
        ])->assertSessionHasErrors('starts_at');

        $this->actingAs($f['guardianA'])->post('/portal/appointments/slots', ['starts_at' => now()->addDay()->format('Y-m-d\TH:i'), 'duration_minutes' => 15])->assertForbidden();

        $this->actingAs($f['guardianA'])->post("/portal/appointments/slots/{$slot->id}/book", ['student_id' => $f['childA']->id]);
        $booking = AppointmentBooking::query()->firstOrFail();

        $this->actingAs($f['guardianB'])->post("/portal/appointments/bookings/{$booking->id}/cancel")->assertForbidden();
        $this->actingAs($f['otherTeacher'])->post("/portal/appointments/slots/{$slot->id}/cancel")->assertForbidden();

        $this->actingAs($f['teacher'])->post("/portal/appointments/slots/{$slot->id}/cancel")->assertRedirect();
        $this->assertSame('cancelled', $booking->fresh()->status);
    }

    public function test_tenant_isolation(): void
    {
        $f = $this->fixtures();
        $slot = $this->createSlot($f['teacher']);

        $otherTenant = Tenant::create(['slug' => 'other-school', 'name' => 'Other', 'locale' => 'ka', 'timezone' => 'Asia/Tbilisi', 'is_active' => true]);
        TenantDomain::create(['tenant_id' => $otherTenant->id, 'domain' => 'other-school.test', 'is_primary' => true]);
        $outsider = User::factory()->create();
        TenantMembership::create(['tenant_id' => $otherTenant->id, 'user_id' => $outsider->id, 'role' => TenantMembership::ROLE_GUARDIAN, 'is_active' => true]);

        $this->actingAs($outsider)
            ->post("http://other-school.test/portal/appointments/slots/{$slot->id}/book", ['student_id' => $f['childA']->id], ['Host' => 'other-school.test'])
            ->assertNotFound();
        $this->actingAs($outsider)
            ->post("http://other-school.test/portal/appointments/slots/{$slot->id}/cancel", [], ['Host' => 'other-school.test'])
            ->assertNotFound();
    }
}
