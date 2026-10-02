<?php

namespace App\Domain\Communications\Actions;

use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\Student;
use App\Domain\Academics\Models\TeacherAssignment;
use App\Domain\Communications\Models\AppointmentBooking;
use App\Domain\Communications\Models\AppointmentSlot;
use App\Domain\Governance\AuditLogger;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Parent–teacher meeting slots and bookings. Booking locks the slot row
 * and counts active bookings inside the transaction, so two guardians
 * racing for the last place cannot both get it.
 */
class ManageAppointments
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function createSlot(int $tenantId, User $teacher, Carbon $startsAt, int $durationMinutes, ?string $location, int $capacity): AppointmentSlot
    {
        if ($startsAt->isPast()) {
            throw ValidationException::withMessages(['starts_at' => 'შეხვედრის დრო მომავალში უნდა იყოს.']);
        }

        $endsAt = $startsAt->copy()->addMinutes($durationMinutes);

        $overlaps = AppointmentSlot::query()
            ->where('tenant_id', $tenantId)
            ->where('teacher_id', $teacher->id)
            ->where('status', AppointmentSlot::STATUS_OPEN)
            ->where('starts_at', '<', $endsAt)
            ->get()
            ->contains(fn (AppointmentSlot $slot) => $slot->starts_at->copy()->addMinutes($slot->duration_minutes)->gt($startsAt));

        if ($overlaps) {
            throw ValidationException::withMessages(['starts_at' => 'ეს დრო გადაფარავს თქვენს სხვა შეხვედრის დროს.']);
        }

        $slot = new AppointmentSlot([
            'teacher_id' => $teacher->id,
            'starts_at' => $startsAt,
            'duration_minutes' => $durationMinutes,
            'location' => $location !== null && trim($location) !== '' ? trim($location) : null,
            'capacity' => $capacity,
            'status' => AppointmentSlot::STATUS_OPEN,
        ]);
        $slot->tenant_id = $tenantId;
        $slot->save();

        return $slot;
    }

    public function cancelSlot(AppointmentSlot $slot, User $teacher): void
    {
        DB::transaction(function () use ($slot, $teacher) {
            $slot->status = AppointmentSlot::STATUS_CANCELLED;
            $slot->save();

            $cancelled = AppointmentBooking::query()
                ->where('tenant_id', $slot->tenant_id)
                ->where('appointment_slot_id', $slot->id)
                ->where('status', AppointmentBooking::STATUS_BOOKED)
                ->update(['status' => AppointmentBooking::STATUS_CANCELLED, 'cancelled_at' => Carbon::now()]);

            $this->auditLogger->record($slot->tenant_id, 'appointment_slot.cancelled', $slot, $teacher->id, ['bookings_cancelled' => $cancelled]);
        });
    }

    public function book(AppointmentSlot $slot, Student $student, User $guardian, ?string $note): AppointmentBooking
    {
        if (! $this->teachesStudent($slot->tenant_id, $slot->teacher_id, $student)) {
            throw ValidationException::withMessages(['appointment_slot_id' => 'ეს მასწავლებელი თქვენს შვილს არ ასწავლის.']);
        }

        return DB::transaction(function () use ($slot, $student, $guardian, $note) {
            $locked = AppointmentSlot::query()
                ->where('tenant_id', $slot->tenant_id)
                ->whereKey($slot->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== AppointmentSlot::STATUS_OPEN || $locked->starts_at->isPast()) {
                throw ValidationException::withMessages(['appointment_slot_id' => 'ეს დრო ხელმისაწვდომი აღარ არის.']);
            }

            $active = AppointmentBooking::query()
                ->where('appointment_slot_id', $locked->id)
                ->where('status', AppointmentBooking::STATUS_BOOKED)
                ->get();

            if ($active->contains('student_id', $student->id)) {
                throw ValidationException::withMessages(['appointment_slot_id' => 'ამ დროზე თქვენი შვილისთვის უკვე დაჯავშნილია.']);
            }

            if ($active->count() >= $locked->capacity) {
                throw ValidationException::withMessages(['appointment_slot_id' => 'ეს დრო უკვე დაკავებულია.']);
            }

            $booking = new AppointmentBooking([
                'appointment_slot_id' => $locked->id,
                'student_id' => $student->id,
                'guardian_id' => $guardian->id,
                'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
                'status' => AppointmentBooking::STATUS_BOOKED,
            ]);
            $booking->tenant_id = $locked->tenant_id;
            $booking->save();

            $this->auditLogger->record($locked->tenant_id, 'appointment.booked', $booking, $guardian->id, [
                'slot_id' => $locked->id,
                'student_id' => $student->id,
            ]);

            return $booking;
        });
    }

    public function cancelBooking(AppointmentBooking $booking, User $actor): void
    {
        if ($booking->status !== AppointmentBooking::STATUS_BOOKED) {
            return;
        }

        $booking->status = AppointmentBooking::STATUS_CANCELLED;
        $booking->cancelled_at = Carbon::now();
        $booking->save();

        $this->auditLogger->record($booking->tenant_id, 'appointment.cancelled', $booking, $actor->id, [
            'slot_id' => $booking->appointment_slot_id,
        ]);
    }

    public function teachesStudent(int $tenantId, int $teacherId, Student $student): bool
    {
        if ($student->school_class_id === null) {
            return false;
        }

        return TeacherAssignment::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $teacherId)
            ->where('school_class_id', $student->school_class_id)
            ->exists()
            || SchoolClass::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($student->school_class_id)
                ->where('homeroom_teacher_id', $teacherId)
                ->exists();
    }
}
