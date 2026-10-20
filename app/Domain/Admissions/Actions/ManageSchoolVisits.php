<?php

namespace App\Domain\Admissions\Actions;

use App\Domain\Admissions\Models\AdmissionLead;
use App\Domain\Admissions\Models\AdmissionVisitBooking;
use App\Domain\Admissions\Models\AdmissionVisitSlot;
use App\Domain\Governance\AuditLogger;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Mail\SchoolVisitMail;
use App\Mail\SchoolVisitStaffMail;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/**
 * School visit booking (CLAUDE.md phase 1: "ვიზიტის დაჯავშნა server-ზე
 * ინახება ტევადობის კონტროლით და queued შეტყობინებით").
 *
 * - The admissions team publishes visit slots with a family capacity.
 * - A family books a slot from the public visit form: the slot row is
 *   locked, capacity and the booking cutoff are re-checked, and the lead
 *   and its booking are created in the same transaction — a full slot
 *   leaves no half-created lead behind.
 * - Confirmation (with a signed cancel link) goes to e-mail contacts, a
 *   notice to the admissions team; both queued after commit.
 * - Cancelling a booking (family link or staff) or a whole slot frees the
 *   places under the same lock and is audited.
 */
class ManageSchoolVisits
{
    /** @var array<int, string> */
    public const STAFF_ROLES = [TenantMembership::ROLE_ADMIN, TenantMembership::ROLE_ACADEMIC_MANAGER, TenantMembership::ROLE_DIRECTOR];

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function createSlot(Tenant $tenant, Carbon $startsAtLocal, int $durationMinutes, int $capacity, ?string $label, ?string $location, User $actor): AdmissionVisitSlot
    {
        $startsAt = $startsAtLocal->copy()->utc();

        if ($startsAt->lte(Carbon::now())) {
            throw ValidationException::withMessages(['date' => 'ვიზიტის დრო მომავალში უნდა იყოს.']);
        }

        if ($durationMinutes < 15 || $durationMinutes > 480) {
            throw ValidationException::withMessages(['duration' => 'ხანგრძლივობა 15 წუთიდან 8 საათამდე.']);
        }

        if ($capacity < 1 || $capacity > 200) {
            throw ValidationException::withMessages(['capacity' => 'ადგილების რაოდენობა 1-დან 200-მდე.']);
        }

        $slot = new AdmissionVisitSlot([
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes($durationMinutes),
            'capacity' => $capacity,
            'booked_count' => 0,
            'label' => $this->clean($label, 120),
            'location' => $this->clean($location, 160),
            'status' => AdmissionVisitSlot::STATUS_OPEN,
            'created_by' => $actor->id,
        ]);
        $slot->tenant_id = $tenant->id;
        $slot->save();

        $this->auditLogger->record($tenant->id, 'admission_visit_slot.created', $slot, $actor->id, [
            'starts_at' => $slot->starts_at->toIso8601String(),
            'capacity' => $capacity,
        ]);

        return $slot;
    }

    /**
     * Creates the lead and its booking together, or neither.
     *
     * @param  array<string, mixed>  $leadAttributes
     */
    public function book(Tenant $tenant, int $slotId, array $leadAttributes, ?string $notifyEmail): AdmissionVisitBooking
    {
        $booking = DB::transaction(function () use ($tenant, $slotId, $leadAttributes, $notifyEmail) {
            $slot = AdmissionVisitSlot::query()->where('tenant_id', $tenant->id)->whereKey($slotId)->lockForUpdate()->first();

            if ($slot === null || $slot->status !== AdmissionVisitSlot::STATUS_OPEN
                || $slot->starts_at->lte(Carbon::now()->addMinutes(AdmissionVisitSlot::BOOKING_CUTOFF_MINUTES))) {
                throw ValidationException::withMessages(['visit_slot_id' => 'ეს დრო აღარ არის ხელმისაწვდომი — აირჩიეთ სხვა.']);
            }

            if ($slot->remaining() < 1) {
                throw ValidationException::withMessages(['visit_slot_id' => 'ამ დროზე ადგილები ამოიწურა — აირჩიეთ სხვა.']);
            }

            $alreadyBooked = AdmissionVisitBooking::query()->where('tenant_id', $tenant->id)
                ->where('admission_visit_slot_id', $slot->id)->where('status', AdmissionVisitBooking::STATUS_BOOKED)
                ->whereHas('lead', fn ($query) => $query->where('contact_value', $leadAttributes['contact_value']))
                ->exists();

            if ($alreadyBooked) {
                throw ValidationException::withMessages(['visit_slot_id' => 'ამ დროზე ვიზიტი უკვე დაჯავშნილი გაქვთ.']);
            }

            /** @var AdmissionLead $lead */
            $lead = $tenant->admissionLeads()->create($leadAttributes + ['stage' => AdmissionLead::STAGE_VISIT_SCHEDULED]);

            $booking = new AdmissionVisitBooking([
                'admission_visit_slot_id' => $slot->id,
                'admission_lead_id' => $lead->id,
                'status' => AdmissionVisitBooking::STATUS_BOOKED,
                'notify_email' => $notifyEmail,
            ]);
            $booking->tenant_id = $tenant->id;
            $booking->save();

            $slot->booked_count += 1;
            $slot->save();

            $this->auditLogger->record($tenant->id, 'admission_visit.booked', $booking, null, [
                'slot_id' => $slot->id,
                'lead_id' => $lead->id,
                'remaining' => $slot->remaining(),
            ]);

            return $booking->setRelation('slot', $slot)->setRelation('lead', $lead);
        });

        $when = $this->when($tenant, $booking->slot);

        if ($booking->notify_email !== null) {
            Mail::to($booking->notify_email)->queue((new SchoolVisitMail(
                SchoolVisitMail::KIND_CONFIRMED, $tenant->name, $when, $booking->slot->location, $this->cancelUrl($booking),
            ))->afterCommit());
        }

        foreach ($this->staffEmails($tenant->id) as $email) {
            Mail::to($email)->queue((new SchoolVisitStaffMail(
                $tenant->name, $when, $booking->lead->guardian_name, $booking->slot->remaining(),
                // Built now: the queue worker has no tenant domain to build it from.
                route('admissions-pipeline.show', $booking->admission_lead_id),
            ))->afterCommit());
        }

        return $booking;
    }

    /**
     * Cancels one booking. $actor is null when the family cancels through
     * the signed link. Cancelling twice is a no-op.
     */
    public function cancelBooking(AdmissionVisitBooking $booking, ?User $actor, ?string $reason): void
    {
        $tenant = Tenant::query()->findOrFail($booking->tenant_id);

        $cancelled = DB::transaction(function () use ($booking, $actor, $reason) {
            $slot = AdmissionVisitSlot::query()->where('tenant_id', $booking->tenant_id)->whereKey($booking->admission_visit_slot_id)->lockForUpdate()->firstOrFail();
            $locked = AdmissionVisitBooking::query()->where('tenant_id', $booking->tenant_id)->whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== AdmissionVisitBooking::STATUS_BOOKED) {
                return null;
            }

            $locked->status = AdmissionVisitBooking::STATUS_CANCELLED;
            $locked->cancelled_at = Carbon::now();
            $locked->cancelled_by = $actor?->id;
            $locked->cancel_reason = $this->clean($reason, 500);
            $locked->save();

            $slot->booked_count = max(0, $slot->booked_count - 1);
            $slot->save();

            $this->auditLogger->record($locked->tenant_id, 'admission_visit.cancelled', $locked, $actor?->id, [
                'slot_id' => $slot->id,
                'by' => $actor === null ? 'family' : 'staff',
            ]);

            return $locked->setRelation('slot', $slot);
        });

        if ($cancelled !== null && $actor !== null && $cancelled->notify_email !== null) {
            Mail::to($cancelled->notify_email)->queue((new SchoolVisitMail(
                SchoolVisitMail::KIND_CANCELLED_BY_SCHOOL, $tenant->name, $this->when($tenant, $cancelled->slot), $cancelled->slot->location, null, $cancelled->cancel_reason,
            ))->afterCommit());
        }

        $booking->refresh();
    }

    /**
     * Cancels a slot and every booking in it; families with an e-mail
     * contact are told why.
     */
    public function cancelSlot(AdmissionVisitSlot $slot, string $reason, User $actor): void
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['reason' => 'გაუქმების მიზეზი სავალდებულოა.']);
        }

        $tenant = Tenant::query()->findOrFail($slot->tenant_id);

        $bookings = DB::transaction(function () use ($slot, $reason, $actor) {
            $locked = AdmissionVisitSlot::query()->where('tenant_id', $slot->tenant_id)->whereKey($slot->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === AdmissionVisitSlot::STATUS_CANCELLED) {
                return collect();
            }

            $bookings = AdmissionVisitBooking::query()->where('tenant_id', $locked->tenant_id)
                ->where('admission_visit_slot_id', $locked->id)->where('status', AdmissionVisitBooking::STATUS_BOOKED)
                ->lockForUpdate()->get();

            foreach ($bookings as $booking) {
                $booking->status = AdmissionVisitBooking::STATUS_CANCELLED;
                $booking->cancelled_at = Carbon::now();
                $booking->cancelled_by = $actor->id;
                $booking->cancel_reason = mb_substr($reason, 0, 500);
                $booking->save();
            }

            $locked->status = AdmissionVisitSlot::STATUS_CANCELLED;
            $locked->cancel_reason = mb_substr($reason, 0, 500);
            $locked->cancelled_at = Carbon::now();
            $locked->booked_count = 0;
            $locked->save();

            $this->auditLogger->record($locked->tenant_id, 'admission_visit_slot.cancelled', $locked, $actor->id, [
                'cancelled_bookings' => $bookings->count(),
                'reason' => $locked->cancel_reason,
            ]);

            return $bookings;
        });

        $slot->refresh();
        $when = $this->when($tenant, $slot);

        foreach ($bookings->whereNotNull('notify_email') as $booking) {
            Mail::to($booking->notify_email)->queue((new SchoolVisitMail(
                SchoolVisitMail::KIND_CANCELLED_BY_SCHOOL, $tenant->name, $when, $slot->location, null, $slot->cancel_reason,
            ))->afterCommit());
        }
    }

    public function cancelUrl(AdmissionVisitBooking $booking): string
    {
        return URL::temporarySignedRoute('admissions.visits.cancel', $booking->slot->starts_at, ['booking' => $booking->id]);
    }

    public function when(Tenant $tenant, AdmissionVisitSlot $slot): string
    {
        $tz = $tenant->timezone ?: 'Asia/Tbilisi';

        return $slot->starts_at->copy()->setTimezone($tz)->format('d.m.Y H:i').'–'.$slot->ends_at->copy()->setTimezone($tz)->format('H:i');
    }

    /**
     * @return array<int, string>
     */
    private function staffEmails(int $tenantId): array
    {
        return User::query()
            ->whereIn('id', TenantMembership::query()->where('tenant_id', $tenantId)->where('is_active', true)
                ->whereIn('role', [TenantMembership::ROLE_ADMIN, TenantMembership::ROLE_ACADEMIC_MANAGER])->select('user_id'))
            ->get()->map(fn (User $user) => $user->email)->filter()->unique()->values()->all();
    }

    private function clean(?string $value, int $max): ?string
    {
        $value = $value === null ? '' : trim($value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
