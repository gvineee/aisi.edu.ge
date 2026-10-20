<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Admissions\Actions\ManageSchoolVisits;
use App\Domain\Admissions\Models\AdmissionVisitBooking;
use App\Domain\Admissions\Models\AdmissionVisitSlot;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * „სკოლის ვიზიტები“ — the admissions team publishes visit times with a
 * family capacity, sees who booked, and cancels a booking or a whole visit.
 * Same roles as the admissions pipeline; every action re-checks them.
 */
class SchoolVisitController extends Controller
{
    public function index(Request $request, CurrentTenant $currentTenant): Response
    {
        $tenant = $currentTenant->get();
        $this->authorizeAccess($tenant->id, $request->user()->id);
        $past = $request->query('view') === 'past';
        $now = Carbon::now();

        $slots = AdmissionVisitSlot::query()->where('tenant_id', $tenant->id)
            ->when($past,
                fn ($query) => $query->where('starts_at', '<', $now)->where('starts_at', '>=', $now->copy()->subDays(90))->orderByDesc('starts_at'),
                fn ($query) => $query->where('starts_at', '>=', $now->copy()->startOfDay())->orderBy('starts_at'))
            ->with(['bookings' => fn ($query) => $query->orderBy('id'), 'bookings.lead'])
            ->limit(100)->get();

        return Inertia::render('portal/school-visits/index', [
            'view' => $past ? 'past' : 'upcoming',
            'timezone' => $tenant->timezone ?: 'Asia/Tbilisi',
            'slots' => $slots->map(fn (AdmissionVisitSlot $slot) => [
                'id' => $slot->id,
                'startsAt' => $slot->starts_at->toIso8601String(),
                'endsAt' => $slot->ends_at->toIso8601String(),
                'label' => $slot->label,
                'location' => $slot->location,
                'capacity' => $slot->capacity,
                'bookedCount' => $slot->booked_count,
                'status' => $slot->status,
                'cancelReason' => $slot->cancel_reason,
                'isPast' => $slot->starts_at->lt($now),
                'bookings' => $slot->bookings->map(fn (AdmissionVisitBooking $booking) => [
                    'id' => $booking->id,
                    'status' => $booking->status,
                    'leadId' => $booking->admission_lead_id,
                    'guardianName' => $booking->lead->guardian_name,
                    'contactValue' => $booking->lead->contact_value,
                    'desiredGrade' => $booking->lead->desired_grade,
                    'bookedAt' => $booking->created_at?->toIso8601String(),
                    'cancelledByFamily' => $booking->status === AdmissionVisitBooking::STATUS_CANCELLED && $booking->cancelled_by === null,
                ])->values()->all(),
            ])->values(),
        ]);
    }

    public function store(Request $request, CurrentTenant $currentTenant, ManageSchoolVisits $visits): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $this->authorizeAccess($tenant->id, $request->user()->id);
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'duration' => ['required', 'integer', 'min:15', 'max:480'],
            'capacity' => ['required', 'integer', 'min:1', 'max:200'],
            'label' => ['nullable', 'string', 'max:120'],
            'location' => ['nullable', 'string', 'max:160'],
            'repeat_weeks' => ['nullable', 'integer', 'min:0', 'max:12'],
        ]);

        $start = Carbon::createFromFormat('Y-m-d H:i', $data['date'].' '.$data['time'], $tenant->timezone ?: 'Asia/Tbilisi');
        $weeks = (int) ($data['repeat_weeks'] ?? 0);

        // All repeats or none.
        DB::transaction(function () use ($visits, $tenant, $start, $weeks, $data, $request) {
            for ($week = 0; $week <= $weeks; $week++) {
                $visits->createSlot($tenant, $start->copy()->addWeeks($week), (int) $data['duration'], (int) $data['capacity'], $data['label'] ?? null, $data['location'] ?? null, $request->user());
            }
        });

        return back()->with('toast', ['type' => 'success', 'message' => $weeks > 0 ? 'დაემატა '.($weeks + 1).' ვიზიტის დრო.' : 'ვიზიტის დრო დაემატა.']);
    }

    public function cancelSlot(Request $request, CurrentTenant $currentTenant, AdmissionVisitSlot $slot, ManageSchoolVisits $visits): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($slot->tenant_id === $tenant->id, 404);
        $this->authorizeAccess($tenant->id, $request->user()->id);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        $visits->cancelSlot($slot, $data['reason'], $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'ვიზიტი გაუქმდა; ელფოსტით დარეგისტრირებულ ოჯახებს შეტყობინება გაეგზავნა.']);
    }

    public function cancelBooking(Request $request, CurrentTenant $currentTenant, AdmissionVisitBooking $booking, ManageSchoolVisits $visits): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($booking->tenant_id === $tenant->id, 404);
        $this->authorizeAccess($tenant->id, $request->user()->id);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        $visits->cancelBooking($booking, $request->user(), $data['reason'] ?? null);

        return back()->with('toast', ['type' => 'success', 'message' => 'ჯავშანი გაუქმდა, ადგილი გათავისუფლდა.']);
    }

    private function authorizeAccess(int $tenantId, int $userId): void
    {
        abort_unless(TenantMembership::userHasAnyActiveRole($tenantId, $userId, ManageSchoolVisits::STAFF_ROLES), 403);
    }
}
