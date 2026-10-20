<?php

namespace Tests\Feature\Admissions;

use App\Domain\Admissions\Models\AdmissionLead;
use App\Domain\Admissions\Models\AdmissionVisitBooking;
use App\Domain\Admissions\Models\AdmissionVisitSlot;
use App\Domain\Governance\Models\AuditEvent;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Mail\SchoolVisitMail;
use App\Mail\SchoolVisitStaffMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * School visit booking (CLAUDE.md phase 1): published slots with capacity,
 * booking from the public visit form under a lock, queued confirmation and
 * staff notice, signed family cancel link, staff cancellations, reminders,
 * cutoff, roles and tenant isolation.
 */
class SchoolVisitBookingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->tenant = TenantDomain::query()->where('domain', 'localhost')->firstOrFail()->tenant;
        $this->admin = User::factory()->create();
        TenantMembership::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->admin->id, 'role' => TenantMembership::ROLE_ADMIN, 'is_active' => true]);
    }

    private function slot(int $capacity = 2, int $inDays = 3, string $time = '10:00', int $repeat = 0): AdmissionVisitSlot
    {
        $this->actingAs($this->admin)->post('/portal/school-visits', [
            'date' => Carbon::now('Asia/Tbilisi')->addDays($inDays)->toDateString(),
            'time' => $time,
            'duration' => 60,
            'capacity' => $capacity,
            'label' => 'ღია კარის დღე',
            'location' => 'მთავარი შენობა',
            'repeat_weeks' => $repeat,
        ])->assertSessionHasNoErrors();

        return AdmissionVisitSlot::query()->orderBy('id')->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function book(?int $slotId, array $overrides = []): TestResponse
    {
        auth()->logout();

        return $this->post(route('admissions.leads.store'), $overrides + [
            'guardian_name' => 'ნინო ჯ.',
            'contact_method' => 'phone',
            'contact_value' => 'nino@example.test',
            'desired_grade' => 'III კლასი',
            'consent_given' => true,
            'visit_slot_id' => $slotId,
        ]);
    }

    public function test_staff_publish_slots_and_the_public_list_shows_only_open_bookable_times(): void
    {
        $slot = $this->slot(capacity: 3, repeat: 2);
        $this->assertSame(3, AdmissionVisitSlot::query()->count());
        $this->assertSame('ღია კარის დღე', $slot->label);
        // 10:00 Tbilisi is stored as 06:00 UTC.
        $this->assertSame('06:00', $slot->starts_at->format('H:i'));

        $this->book($slot->id)->assertSessionHasNoErrors();

        $json = $this->getJson('/admissions/visit-slots')->assertOk()->json('slots');
        $this->assertCount(3, $json);
        $this->assertSame(2, $json[0]['remaining']);
        $this->assertArrayNotHasKey('bookings', $json[0]);
        $this->assertStringNotContainsString('nino', json_encode($json));

        // Too close to start, cancelled, and past slots are not offered.
        $soon = $slot->replicate();
        $soon->starts_at = Carbon::now()->addMinutes(30);
        $soon->ends_at = Carbon::now()->addMinutes(90);
        $soon->booked_count = 0;
        $soon->save();
        $this->book($soon->id, ['contact_value' => 'other@example.test'])->assertSessionHasErrors('visit_slot_id');
        $this->assertCount(3, $this->getJson('/admissions/visit-slots')->json('slots'));
    }

    public function test_booking_creates_the_lead_and_booking_together_and_notifies(): void
    {
        $slot = $this->slot();

        $this->book($slot->id)->assertSessionHasNoErrors();

        $lead = AdmissionLead::query()->firstOrFail();
        $this->assertSame(AdmissionLead::STAGE_VISIT_SCHEDULED, $lead->stage);
        $this->assertSame('email', $lead->contact_method);
        $booking = AdmissionVisitBooking::query()->firstOrFail();
        $this->assertSame($lead->id, $booking->admission_lead_id);
        $this->assertSame('nino@example.test', $booking->notify_email);
        $this->assertSame(1, $slot->fresh()->booked_count);

        Mail::assertQueued(SchoolVisitMail::class, fn (SchoolVisitMail $m) => $m->kind === SchoolVisitMail::KIND_CONFIRMED
            && $m->hasTo('nino@example.test') && str_contains((string) $m->cancelUrl, '/admissions/visits/'.$booking->id.'/cancel'));
        Mail::assertQueued(SchoolVisitStaffMail::class, fn (SchoolVisitStaffMail $m) => $m->hasTo($this->admin->email) && $m->remaining === 1);
        $this->assertTrue(AuditEvent::query()->where('action', 'admission_visit.booked')->exists());

        // A phone contact gets no e-mail but is booked all the same.
        $this->book($slot->id, ['contact_value' => '+995555000111'])->assertSessionHasNoErrors();
        $this->assertNull(AdmissionVisitBooking::query()->latest('id')->firstOrFail()->notify_email);
        Mail::assertQueuedCount(3);

        // The pipeline shows the booking on the lead.
        $this->actingAs($this->admin)->get("/portal/admissions-pipeline/{$lead->id}")->assertInertia(fn (Assert $page) => $page
            ->where('lead.visitBookings.0.status', 'booked'));
    }

    public function test_a_full_slot_refuses_the_booking_and_creates_no_lead(): void
    {
        $slot = $this->slot(capacity: 1);

        $this->book($slot->id)->assertSessionHasNoErrors();
        $this->book($slot->id, ['contact_value' => 'second@example.test'])->assertSessionHasErrors('visit_slot_id');

        $this->assertSame(1, AdmissionLead::query()->count());
        $this->assertSame(1, $slot->fresh()->booked_count);
        $this->assertSame(0, $this->getJson('/admissions/visit-slots')->json('slots.0.remaining'));
    }

    public function test_the_same_family_cannot_take_two_places_in_one_slot(): void
    {
        $slot = $this->slot(capacity: 5);

        $this->book($slot->id)->assertSessionHasNoErrors();
        $this->book($slot->id)->assertSessionHasErrors('visit_slot_id');

        $this->assertSame(1, $slot->fresh()->booked_count);
    }

    public function test_family_cancels_through_the_signed_link_only(): void
    {
        $slot = $this->slot(capacity: 1);
        $this->book($slot->id)->assertSessionHasNoErrors();
        $booking = AdmissionVisitBooking::query()->firstOrFail();

        $this->get("/admissions/visits/{$booking->id}/cancel")->assertForbidden();
        $this->post("/admissions/visits/{$booking->id}/cancel")->assertForbidden();

        $url = URL::temporarySignedRoute('admissions.visits.cancel', $slot->starts_at, ['booking' => $booking->id]);
        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('public/visit-cancel')->where('visit.status', 'booked'));

        $this->post($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('visit.status', 'cancelled')->where('justCancelled', true));
        $this->post($url)->assertOk();

        $this->assertSame(0, $slot->fresh()->booked_count);
        $this->assertNull($booking->fresh()->cancelled_by);
        // The freed place can be booked again.
        $this->book($slot->id, ['contact_value' => 'next@example.test'])->assertSessionHasNoErrors();

        $expired = URL::temporarySignedRoute('admissions.visits.cancel', Carbon::now()->subMinute(), ['booking' => $booking->id]);
        $this->get($expired)->assertForbidden();
    }

    public function test_staff_cancel_a_visit_with_a_reason_and_families_are_told(): void
    {
        $slot = $this->slot(capacity: 3);
        $this->book($slot->id)->assertSessionHasNoErrors();
        $this->book($slot->id, ['contact_value' => '+995555000222'])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->post("/portal/school-visits/{$slot->id}/cancel", ['reason' => 'x'])->assertSessionHasErrors('reason');
        $this->actingAs($this->admin)->post("/portal/school-visits/{$slot->id}/cancel", ['reason' => 'შენობაში რემონტია'])->assertSessionHasNoErrors();

        $slot->refresh();
        $this->assertSame(AdmissionVisitSlot::STATUS_CANCELLED, $slot->status);
        $this->assertSame(0, AdmissionVisitBooking::query()->where('status', AdmissionVisitBooking::STATUS_BOOKED)->count());
        Mail::assertQueued(SchoolVisitMail::class, fn (SchoolVisitMail $m) => $m->kind === SchoolVisitMail::KIND_CANCELLED_BY_SCHOOL && $m->reason === 'შენობაში რემონტია');
        $this->book($slot->id, ['contact_value' => 'late@example.test'])->assertSessionHasErrors('visit_slot_id');
        $this->assertCount(0, $this->getJson('/admissions/visit-slots')->json('slots'));
    }

    public function test_staff_cancel_one_booking_and_the_place_is_freed(): void
    {
        $slot = $this->slot(capacity: 1);
        $this->book($slot->id)->assertSessionHasNoErrors();
        $booking = AdmissionVisitBooking::query()->firstOrFail();

        $this->actingAs($this->admin)->post("/portal/school-visit-bookings/{$booking->id}/cancel")->assertSessionHasNoErrors();

        $this->assertSame(AdmissionVisitBooking::STATUS_CANCELLED, $booking->fresh()->status);
        $this->assertSame($this->admin->id, $booking->fresh()->cancelled_by);
        $this->assertSame(0, $slot->fresh()->booked_count);
        $this->actingAs($this->admin)->get('/portal/school-visits')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('portal/school-visits/index')->where('slots.0.bookings.0.status', 'cancelled'));
    }

    public function test_reminders_go_once_to_e_mail_contacts_within_a_day(): void
    {
        $slot = $this->slot(capacity: 3, inDays: 3);
        $this->book($slot->id)->assertSessionHasNoErrors();
        Mail::fake();

        $this->artisan('admissions:send-visit-reminders')->assertSuccessful();
        Mail::assertNothingQueued();

        Carbon::setTestNow($slot->starts_at->copy()->subHours(20));
        $this->artisan('admissions:send-visit-reminders')->assertSuccessful();
        $this->artisan('admissions:send-visit-reminders')->assertSuccessful();
        Carbon::setTestNow();

        Mail::assertQueuedCount(1);
        Mail::assertQueued(SchoolVisitMail::class, fn (SchoolVisitMail $m) => $m->kind === SchoolVisitMail::KIND_REMINDER && $m->hasTo('nino@example.test'));
    }

    public function test_roles_and_validation(): void
    {
        $teacher = User::factory()->create();
        TenantMembership::create(['tenant_id' => $this->tenant->id, 'user_id' => $teacher->id, 'role' => TenantMembership::ROLE_TEACHER, 'is_active' => true]);
        $slot = $this->slot();

        $this->actingAs($teacher)->get('/portal/school-visits')->assertForbidden();
        $this->actingAs($teacher)->post("/portal/school-visits/{$slot->id}/cancel", ['reason' => 'არ მინდა'])->assertForbidden();
        $this->actingAs($this->admin)->post('/portal/school-visits', [
            'date' => Carbon::now('Asia/Tbilisi')->subDay()->toDateString(), 'time' => '10:00', 'duration' => 60, 'capacity' => 2,
        ])->assertSessionHasErrors('date');
        $this->actingAs($this->admin)->post('/portal/school-visits', [
            'date' => Carbon::now('Asia/Tbilisi')->addDay()->toDateString(), 'time' => '10:00', 'duration' => 60, 'capacity' => 0,
        ])->assertSessionHasErrors('capacity');
        $this->assertSame(1, AdmissionVisitSlot::query()->count());
    }

    public function test_other_tenants_slots_and_bookings_are_out_of_reach(): void
    {
        $slot = $this->slot();
        $this->book($slot->id)->assertSessionHasNoErrors();
        $booking = AdmissionVisitBooking::query()->firstOrFail();

        $other = Tenant::create(['slug' => 'other-visits', 'name' => 'Other', 'locale' => 'ka', 'timezone' => 'Asia/Tbilisi', 'is_active' => true]);
        TenantDomain::create(['tenant_id' => $other->id, 'domain' => 'other.test', 'is_primary' => true]);
        $otherAdmin = User::factory()->create();
        TenantMembership::create(['tenant_id' => $other->id, 'user_id' => $otherAdmin->id, 'role' => TenantMembership::ROLE_ADMIN, 'is_active' => true]);

        $this->getJson('http://other.test/admissions/visit-slots')->assertOk()->assertJsonCount(0, 'slots');
        auth()->logout();
        $this->post('http://other.test/admissions/leads', [
            'guardian_name' => 'უცხო', 'contact_method' => 'phone', 'contact_value' => '+995555999999', 'consent_given' => true, 'visit_slot_id' => $slot->id,
        ])->assertSessionHasErrors('visit_slot_id');
        $this->actingAs($otherAdmin)->post("http://other.test/portal/school-visits/{$slot->id}/cancel", ['reason' => 'უცხო სკოლა'])->assertNotFound();
        $this->actingAs($otherAdmin)->post("http://other.test/portal/school-visit-bookings/{$booking->id}/cancel")->assertNotFound();

        $this->assertSame(1, $slot->fresh()->booked_count);
        $this->assertSame(1, AdmissionLead::query()->withoutGlobalScope('tenant')->count());
    }
}
