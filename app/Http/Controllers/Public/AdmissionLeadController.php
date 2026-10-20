<?php

namespace App\Http\Controllers\Public;

use App\Domain\Admissions\Actions\ManageSchoolVisits;
use App\Domain\Admissions\Models\AdmissionLead;
use App\Domain\Admissions\Models\AdmissionVisitBooking;
use App\Domain\Admissions\Models\AdmissionVisitSlot;
use App\Domain\Tenancy\CurrentTenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Public\StoreAdmissionLeadRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public visit form: an interest lead, optionally with a booked visit
 * slot; the open slots it offers; and cancelling a booking through the
 * signed link from the confirmation e-mail.
 */
class AdmissionLeadController extends Controller
{
    public function store(StoreAdmissionLeadRequest $request, CurrentTenant $currentTenant, ManageSchoolVisits $visits): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $data = $request->validated();

        // The form has one contact field; an e-mail address there is an e-mail contact.
        $isEmail = filter_var($data['contact_value'], FILTER_VALIDATE_EMAIL) !== false;
        $contactMethod = $isEmail ? 'email' : $data['contact_method'];

        // Duplicate-lead warning (docs/02 section 5.1): flag, don't block —
        // the school still wants to see repeat interest, just merged for
        // follow-up instead of treated as unrelated new contacts.
        $existing = AdmissionLead::query()
            ->where('tenant_id', $tenant->id)
            ->where('contact_value', $data['contact_value'])
            ->where('created_at', '>=', now()->subDay())
            ->latest('created_at')
            ->first();

        $attributes = [
            'guardian_name' => $data['guardian_name'],
            'contact_method' => $contactMethod,
            'contact_value' => $data['contact_value'],
            'desired_grade' => $data['desired_grade'] ?? null,
            'preferred_date' => $data['preferred_date'] ?? null,
            'consent_given' => true,
            'stage' => AdmissionLead::STAGE_NEW,
            'duplicate_of_lead_id' => $existing?->id,
            'duplicate_of_checked_at' => now(),
        ];

        if (! empty($data['visit_slot_id'])) {
            unset($attributes['stage'], $attributes['preferred_date']);
            $booking = $visits->book($tenant, (int) $data['visit_slot_id'], $attributes, $isEmail ? $data['contact_value'] : null);

            Inertia::flash('toast', [
                'type' => 'success',
                'message' => 'ვიზიტი დაჯავშნილია: '.$visits->when($tenant, $booking->slot).'.'.($isEmail ? ' დადასტურება ელფოსტაზე გამოგიგზავნეთ.' : ''),
            ]);

            return back();
        }

        $tenant->admissionLeads()->create($attributes);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'მოთხოვნა მივიღეთ. სკოლის გუნდი დაგიკავშირდებათ თქვენ მიერ მითითებულ ნომერზე.',
        ]);

        return back();
    }

    /**
     * Open visit times for the public form. Only time, label, place and
     * whether places are left — nothing about who booked.
     */
    public function slots(CurrentTenant $currentTenant): JsonResponse
    {
        $tenant = $currentTenant->get();
        $now = Carbon::now();

        $slots = AdmissionVisitSlot::query()->where('tenant_id', $tenant->id)
            ->where('status', AdmissionVisitSlot::STATUS_OPEN)
            ->where('starts_at', '>', $now->copy()->addMinutes(AdmissionVisitSlot::BOOKING_CUTOFF_MINUTES))
            ->where('starts_at', '<', $now->copy()->addDays(AdmissionVisitSlot::BOOKING_HORIZON_DAYS))
            ->orderBy('starts_at')->limit(40)->get();

        return response()->json([
            'slots' => $slots->map(fn (AdmissionVisitSlot $slot) => [
                'id' => $slot->id,
                'startsAt' => $slot->starts_at->toIso8601String(),
                'endsAt' => $slot->ends_at->toIso8601String(),
                'label' => $slot->label,
                'location' => $slot->location,
                'remaining' => $slot->remaining(),
            ])->values(),
        ])->header('Cache-Control', 'no-store');
    }

    public function showCancel(CurrentTenant $currentTenant, AdmissionVisitBooking $booking): Response
    {
        return Inertia::render('public/visit-cancel', $this->cancelProps($currentTenant, $booking, false));
    }

    public function cancel(CurrentTenant $currentTenant, AdmissionVisitBooking $booking, ManageSchoolVisits $visits): Response
    {
        abort_unless($booking->tenant_id === $currentTenant->get()->id, 404);
        $visits->cancelBooking($booking, null, null);

        return Inertia::render('public/visit-cancel', $this->cancelProps($currentTenant, $booking->refresh(), true));
    }

    /**
     * @return array<string, mixed>
     */
    private function cancelProps(CurrentTenant $currentTenant, AdmissionVisitBooking $booking, bool $justCancelled): array
    {
        $tenant = $currentTenant->get();
        abort_unless($booking->tenant_id === $tenant->id, 404);
        $booking->loadMissing('slot');

        return [
            'visit' => [
                'when' => app(ManageSchoolVisits::class)->when($tenant, $booking->slot),
                'location' => $booking->slot->location,
                'status' => $booking->status,
                'slotCancelled' => $booking->slot->status === AdmissionVisitSlot::STATUS_CANCELLED,
            ],
            'justCancelled' => $justCancelled,
        ];
    }
}
