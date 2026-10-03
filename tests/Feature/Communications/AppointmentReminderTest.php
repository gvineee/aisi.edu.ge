<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Models\AppointmentBooking;
use App\Domain\Communications\Models\AppointmentSlot;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Mail\AppointmentReminderMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Meetings starting within 24 hours are reminded once (guardian and
 * teacher), never for cancelled bookings or later meetings, and a second
 * scheduler run sends nothing.
 */
class AppointmentReminderTest extends TestCase
{
    use RefreshDatabase;

    public function test_upcoming_bookings_are_reminded_exactly_once(): void
    {
        Mail::fake();
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;
        $year = $tenant->academicYears()->create(['name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-15', 'is_current' => true]);
        $class = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => 'VI']);
        $student = $tenant->students()->create(['school_class_id' => $class->id, 'first_name' => 'ნიკა', 'last_name' => 'დ.', 'is_active' => true]);
        $teacher = User::factory()->create();
        $guardian = User::factory()->create();
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $teacher->id, 'role' => TenantMembership::ROLE_TEACHER, 'is_active' => true]);
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $guardian->id, 'role' => TenantMembership::ROLE_GUARDIAN, 'is_active' => true]);

        $book = function (Carbon $startsAt, string $status) use ($tenant, $teacher, $guardian, $student): AppointmentBooking {
            $slot = new AppointmentSlot(['teacher_id' => $teacher->id, 'starts_at' => $startsAt, 'duration_minutes' => 15, 'location' => 'ოთახი 12', 'capacity' => 1, 'status' => AppointmentSlot::STATUS_OPEN]);
            $slot->tenant_id = $tenant->id;
            $slot->save();
            $booking = new AppointmentBooking(['appointment_slot_id' => $slot->id, 'student_id' => $student->id, 'guardian_id' => $guardian->id, 'note' => 'პირადი შენიშვნა', 'status' => $status]);
            $booking->tenant_id = $tenant->id;
            $booking->save();

            return $booking;
        };

        $soon = $book(Carbon::now()->addHours(3), AppointmentBooking::STATUS_BOOKED);
        $later = $book(Carbon::now()->addDays(3), AppointmentBooking::STATUS_BOOKED);
        $cancelled = $book(Carbon::now()->addHours(5), AppointmentBooking::STATUS_CANCELLED);

        $this->artisan('appointments:send-reminders')->assertSuccessful();
        $this->artisan('appointments:send-reminders')->assertSuccessful();

        Mail::assertQueued(AppointmentReminderMail::class, 2);
        Mail::assertQueued(AppointmentReminderMail::class, fn (AppointmentReminderMail $m) => $m->hasTo($guardian->email) && $m->withName === $teacher->name);
        Mail::assertQueued(AppointmentReminderMail::class, fn (AppointmentReminderMail $m) => $m->hasTo($teacher->email) && ! str_contains((string) $m->content()->htmlString, 'პირადი'));
        $this->assertNotNull($soon->fresh()->reminder_sent_at);
        $this->assertNull($later->fresh()->reminder_sent_at);
        $this->assertNull($cancelled->fresh()->reminder_sent_at);
    }
}
