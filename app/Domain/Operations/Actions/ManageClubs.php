<?php

namespace App\Domain\Operations\Actions;

use App\Domain\Academics\Models\Student;
use App\Domain\Governance\AuditLogger;
use App\Domain\Operations\Models\Club;
use App\Domain\Operations\Models\ClubEnrollment;
use App\Domain\Operations\Models\ClubWaitlistEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Club enrollment with a hard capacity (CLAUDE-PLATFORM-MODULES §11). Every
 * change that frees or adds seats runs inside one transaction holding the
 * club row lock and immediately promotes the oldest waiting students, so a
 * cancellation and its promotion are atomic (done synchronously rather
 * than in a queued job — same guarantee, no window where a seat is free
 * while someone waits).
 */
class ManageClubs
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @return string `enrolled` or `waitlisted`
     */
    public function join(Club $club, Student $student, User $requester): string
    {
        return DB::transaction(function () use ($club, $student, $requester) {
            $locked = $this->lock($club);

            if (! $locked->is_open) {
                throw ValidationException::withMessages(['club' => 'ამ კლუბზე რეგისტრაცია დახურულია.']);
            }

            $enrollment = ClubEnrollment::query()->where('club_id', $locked->id)->where('student_id', $student->id)->first();

            if ($enrollment?->status === ClubEnrollment::STATUS_ENROLLED) {
                throw ValidationException::withMessages(['club' => 'მოსწავლე უკვე ჩარიცხულია.']);
            }

            $waiting = ClubWaitlistEntry::query()->where('club_id', $locked->id)->where('student_id', $student->id)->first();

            if ($waiting?->status === ClubWaitlistEntry::STATUS_WAITING) {
                throw ValidationException::withMessages(['club' => 'მოსწავლე უკვე რიგშია.']);
            }

            // Nobody jumps the queue: a free seat goes to a newcomer only
            // when no one is already waiting.
            $hasQueue = ClubWaitlistEntry::query()->where('club_id', $locked->id)->where('status', ClubWaitlistEntry::STATUS_WAITING)->exists();

            if (! $hasQueue && $this->enrolledCount($locked) < $locked->capacity) {
                $this->enroll($locked, $student->id, $requester->id, $enrollment);
                $this->auditLogger->record($locked->tenant_id, 'club.enrolled', $locked, $requester->id, ['student_id' => $student->id]);

                return 'enrolled';
            }

            $entry = $waiting ?? new ClubWaitlistEntry(['club_id' => $locked->id, 'student_id' => $student->id]);

            if ($waiting !== null) {
                // Re-joining goes to the back of the queue.
                $waiting->delete();
                $entry = new ClubWaitlistEntry(['club_id' => $locked->id, 'student_id' => $student->id]);
            }

            $entry->tenant_id = $locked->tenant_id;
            $entry->status = ClubWaitlistEntry::STATUS_WAITING;
            $entry->requested_by = $requester->id;
            $entry->save();

            $this->auditLogger->record($locked->tenant_id, 'club.waitlisted', $locked, $requester->id, ['student_id' => $student->id]);

            return 'waitlisted';
        });
    }

    public function leave(Club $club, Student $student, User $actor): void
    {
        DB::transaction(function () use ($club, $student, $actor) {
            $locked = $this->lock($club);

            $enrollment = ClubEnrollment::query()->where('club_id', $locked->id)->where('student_id', $student->id)->where('status', ClubEnrollment::STATUS_ENROLLED)->first();
            $entry = ClubWaitlistEntry::query()->where('club_id', $locked->id)->where('student_id', $student->id)->where('status', ClubWaitlistEntry::STATUS_WAITING)->first();

            if ($enrollment !== null) {
                $enrollment->status = ClubEnrollment::STATUS_CANCELLED;
                $enrollment->save();
                $this->auditLogger->record($locked->tenant_id, 'club.left', $locked, $actor->id, ['student_id' => $student->id]);
                $this->promote($locked, $actor);
            } elseif ($entry !== null) {
                $entry->status = ClubWaitlistEntry::STATUS_CANCELLED;
                $entry->save();
                $this->auditLogger->record($locked->tenant_id, 'club.waitlist_left', $locked, $actor->id, ['student_id' => $student->id]);
            }
        });
    }

    public function updateCapacity(Club $club, int $capacity, User $actor): void
    {
        DB::transaction(function () use ($club, $capacity, $actor) {
            $locked = $this->lock($club);

            if ($capacity < $this->enrolledCount($locked)) {
                throw ValidationException::withMessages(['capacity' => 'ტევადობა ჩარიცხულების რაოდენობაზე ნაკლები ვერ იქნება.']);
            }

            $locked->capacity = $capacity;
            $locked->save();
            $this->auditLogger->record($locked->tenant_id, 'club.capacity_changed', $locked, $actor->id, ['capacity' => $capacity]);
            $this->promote($locked, $actor);
        });

        $club->refresh();
    }

    private function promote(Club $club, User $actor): void
    {
        while ($this->enrolledCount($club) < $club->capacity) {
            $next = ClubWaitlistEntry::query()
                ->where('club_id', $club->id)
                ->where('status', ClubWaitlistEntry::STATUS_WAITING)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($next === null) {
                return;
            }

            $existing = ClubEnrollment::query()->where('club_id', $club->id)->where('student_id', $next->student_id)->first();
            $this->enroll($club, $next->student_id, $next->requested_by, $existing);

            $next->status = ClubWaitlistEntry::STATUS_PROMOTED;
            $next->save();

            $this->auditLogger->record($club->tenant_id, 'club.promoted_from_waitlist', $club, $actor->id, ['student_id' => $next->student_id]);
        }
    }

    private function enroll(Club $club, int $studentId, int $requesterId, ?ClubEnrollment $existing): void
    {
        $enrollment = $existing ?? new ClubEnrollment(['club_id' => $club->id, 'student_id' => $studentId]);
        $enrollment->tenant_id = $club->tenant_id;
        $enrollment->status = ClubEnrollment::STATUS_ENROLLED;
        $enrollment->requested_by = $requesterId;
        $enrollment->save();
    }

    private function enrolledCount(Club $club): int
    {
        return ClubEnrollment::query()->where('club_id', $club->id)->where('status', ClubEnrollment::STATUS_ENROLLED)->count();
    }

    private function lock(Club $club): Club
    {
        return Club::query()->where('tenant_id', $club->tenant_id)->whereKey($club->id)->lockForUpdate()->firstOrFail();
    }
}
