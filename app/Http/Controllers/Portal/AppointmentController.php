<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\TeacherAssignment;
use App\Domain\Communications\Actions\ManageAppointments;
use App\Domain\Communications\Models\AppointmentBooking;
use App\Domain\Communications\Models\AppointmentSlot;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "შეხვედრები" — parent–teacher meetings. A teacher manages only their own
 * slots; a guardian books only for their own active children and only
 * with that child's teachers (class subject teacher or homeroom).
 */
class AppointmentController extends Controller
{
    public function index(Request $request, CurrentTenant $currentTenant, ManageAppointments $appointments): Response
    {
        $tenant = $currentTenant->get();
        $user = $request->user();

        $isTeacher = TenantMembership::userHasActiveRole($tenant->id, $user->id, TenantMembership::ROLE_TEACHER);
        $isGuardian = TenantMembership::userHasActiveRole($tenant->id, $user->id, TenantMembership::ROLE_GUARDIAN);
        abort_unless($isTeacher || $isGuardian, 403);

        $teacherView = null;

        if ($isTeacher) {
            $slots = AppointmentSlot::query()
                ->where('tenant_id', $tenant->id)
                ->where('teacher_id', $user->id)
                ->where('starts_at', '>=', Carbon::now()->subDay())
                ->with(['activeBookings.student', 'activeBookings.guardian'])
                ->orderBy('starts_at')
                ->get();

            $teacherView = $slots->map(fn (AppointmentSlot $slot) => [
                'id' => $slot->id,
                'startsAt' => $slot->starts_at->toIso8601String(),
                'durationMinutes' => $slot->duration_minutes,
                'location' => $slot->location,
                'capacity' => $slot->capacity,
                'status' => $slot->status,
                'bookings' => $slot->activeBookings->map(fn (AppointmentBooking $booking) => [
                    'id' => $booking->id,
                    'studentName' => $booking->student->fullName(),
                    'guardianName' => $booking->guardian->name ?? null,
                    'note' => $booking->note,
                ])->values()->all(),
            ])->values();
        }

        $guardianView = null;

        if ($isGuardian) {
            $links = GuardianLink::query()
                ->where('tenant_id', $tenant->id)
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->with(['student' => fn ($query) => $query->where('is_active', true)])
                ->get()
                ->filter(fn (GuardianLink $link) => $link->student !== null && $link->student->school_class_id !== null);

            $guardianView = $links->map(function (GuardianLink $link) use ($tenant, $user) {
                $student = $link->student;
                $teacherIds = TeacherAssignment::query()->where('tenant_id', $tenant->id)->where('school_class_id', $student->school_class_id)->pluck('user_id')
                    ->merge(SchoolClass::query()->where('tenant_id', $tenant->id)->whereKey($student->school_class_id)->whereNotNull('homeroom_teacher_id')->pluck('homeroom_teacher_id'))
                    ->unique()->values();

                $slots = AppointmentSlot::query()
                    ->where('tenant_id', $tenant->id)
                    ->whereIn('teacher_id', $teacherIds)
                    ->where('status', AppointmentSlot::STATUS_OPEN)
                    ->where('starts_at', '>', Carbon::now())
                    ->withCount('activeBookings')
                    ->with('teacher')
                    ->orderBy('starts_at')
                    ->get();

                $bookings = AppointmentBooking::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('student_id', $student->id)
                    ->where('guardian_id', $user->id)
                    ->where('status', AppointmentBooking::STATUS_BOOKED)
                    ->with('slot.teacher')
                    ->get();

                return [
                    'id' => $student->id,
                    'name' => $student->fullName(),
                    'bookings' => $bookings->map(fn (AppointmentBooking $booking) => [
                        'id' => $booking->id,
                        'teacherName' => $booking->slot->teacher->name ?? null,
                        'startsAt' => $booking->slot->starts_at->toIso8601String(),
                        'location' => $booking->slot->location,
                        'slotCancelled' => $booking->slot->status === AppointmentSlot::STATUS_CANCELLED,
                    ])->values()->all(),
                    'slots' => $slots
                        ->filter(fn (AppointmentSlot $slot) => $slot->active_bookings_count < $slot->capacity
                            && ! $bookings->contains('appointment_slot_id', $slot->id))
                        ->map(fn (AppointmentSlot $slot) => [
                            'id' => $slot->id,
                            'teacherName' => $slot->teacher->name ?? null,
                            'startsAt' => $slot->starts_at->toIso8601String(),
                            'durationMinutes' => $slot->duration_minutes,
                            'location' => $slot->location,
                        ])->values()->all(),
                ];
            })->values();
        }

        return Inertia::render('portal/appointments/index', [
            'teacherSlots' => $teacherView,
            'children' => $guardianView,
        ]);
    }

    public function storeSlot(Request $request, CurrentTenant $currentTenant, ManageAppointments $appointments): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $user = $request->user();
        abort_unless(TenantMembership::userHasActiveRole($tenant->id, $user->id, TenantMembership::ROLE_TEACHER), 403);

        $data = $request->validate([
            'starts_at' => ['required', 'date'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:180'],
            'location' => ['nullable', 'string', 'max:255'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);

        $appointments->createSlot(
            $tenant->id,
            $user,
            Carbon::parse($data['starts_at'], 'Asia/Tbilisi')->utc(),
            (int) $data['duration_minutes'],
            $data['location'] ?? null,
            (int) ($data['capacity'] ?? 1),
        );

        return back();
    }

    public function cancelSlot(Request $request, CurrentTenant $currentTenant, AppointmentSlot $slot, ManageAppointments $appointments): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($slot->tenant_id === $tenant->id, 404);
        abort_unless($slot->teacher_id === $request->user()->id, 403);

        $appointments->cancelSlot($slot, $request->user());

        return back();
    }

    public function book(Request $request, CurrentTenant $currentTenant, AppointmentSlot $slot, ManageAppointments $appointments): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($slot->tenant_id === $tenant->id, 404);
        $user = $request->user();

        $data = $request->validate([
            'student_id' => ['required', 'integer'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $link = GuardianLink::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('student_id', (int) $data['student_id'])
            ->where('is_active', true)
            ->with('student')
            ->first();
        abort_unless($link !== null && TenantMembership::userHasActiveRole($tenant->id, $user->id, TenantMembership::ROLE_GUARDIAN), 403);

        $appointments->book($slot, $link->student, $user, $data['note'] ?? null);

        return back();
    }

    public function cancelBooking(Request $request, CurrentTenant $currentTenant, AppointmentBooking $booking, ManageAppointments $appointments): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($booking->tenant_id === $tenant->id, 404);
        $user = $request->user();
        $booking->loadMissing('slot');

        $isOwner = $booking->guardian_id === $user->id;
        $isTeacher = $booking->slot->teacher_id === $user->id;
        abort_unless($isOwner || $isTeacher, 403);

        $appointments->cancelBooking($booking, $user);

        return back();
    }
}
