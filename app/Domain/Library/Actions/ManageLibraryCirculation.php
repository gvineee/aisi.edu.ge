<?php

namespace App\Domain\Library\Actions;

use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Academics\Models\Student;
use App\Domain\Governance\AuditLogger;
use App\Domain\Library\Models\LibraryCopy;
use App\Domain\Library\Models\LibraryLoan;
use App\Domain\Library\Models\LibraryReservation;
use App\Domain\Library\Models\LibraryResource;
use App\Domain\Tenancy\Models\Tenant;
use App\Mail\LibraryNoticeMail;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Library circulation (docs/02 §5.5: "ფიზიკური ეგზემპლარი, სესხება,
 * დაბრუნების ვადა, reservation და availability"; §170 "ერთი ეგზემპლარი
 * ერთ აქტიურ სესხში").
 *
 * - A copy is lent only while available, or while held for this very
 *   student's ready reservation. The loan's unique active_key (copy id)
 *   makes a second active loan of the same copy impossible even under
 *   concurrent requests; the copy row is locked as well.
 * - A returned copy goes to the first waiting reservation (held for
 *   HOLD_DAYS, guardians told by e-mail) or back on the shelf. Expired
 *   holds pass the copy on the same way.
 * - Renewal is refused while others are waiting for the title.
 * - Every change is audited; nothing is deleted.
 */
class ManageLibraryCirculation
{
    public const LOAN_DAYS = 14;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array<int, string>  $inventoryNumbers  empty → $count numbers are generated
     * @return array<int, LibraryCopy>
     */
    public function addCopies(LibraryResource $resource, array $inventoryNumbers, int $count, User $actor): array
    {
        $numbers = array_values(array_unique(array_filter(array_map(fn (string $n) => mb_substr(trim($n), 0, 40), $inventoryNumbers), fn (string $n) => $n !== '')));

        if ($numbers === []) {
            if ($count < 1 || $count > 100) {
                throw ValidationException::withMessages(['count' => 'ეგზემპლარების რაოდენობა 1-დან 100-მდე.']);
            }

            $next = LibraryCopy::query()->where('tenant_id', $resource->tenant_id)->where('library_resource_id', $resource->id)->count() + 1;

            for ($i = 0; $i < $count; $i++) {
                $numbers[] = sprintf('%d-%03d', $resource->id, $next + $i);
            }
        }

        $taken = LibraryCopy::query()->where('tenant_id', $resource->tenant_id)->whereIn('inventory_number', $numbers)->pluck('inventory_number')->all();

        if ($taken !== []) {
            throw ValidationException::withMessages(['inventory_numbers' => 'ინვენტარის ნომერი უკვე გამოყენებულია: '.implode(', ', array_slice($taken, 0, 5))]);
        }

        return DB::transaction(function () use ($resource, $numbers, $actor) {
            $copies = [];

            foreach ($numbers as $number) {
                $copy = new LibraryCopy([
                    'library_resource_id' => $resource->id,
                    'inventory_number' => $number,
                    'status' => LibraryCopy::STATUS_AVAILABLE,
                    'created_by' => $actor->id,
                ]);
                $copy->tenant_id = $resource->tenant_id;
                $copy->save();
                $copies[] = $copy;
                // A new copy may satisfy someone already waiting.
                $this->releaseCopy($copy);
            }

            $this->auditLogger->record($resource->tenant_id, 'library_copy.added', $resource, $actor->id, ['inventory_numbers' => $numbers]);

            return $copies;
        });
    }

    public function lend(Tenant $tenant, string $inventoryNumber, ?Student $student, ?User $staffBorrower, Carbon $dueOn, User $actor): LibraryLoan
    {
        if (($student === null) === ($staffBorrower === null)) {
            throw ValidationException::withMessages(['borrower' => 'აირჩიეთ მსესხებელი.']);
        }

        if ($student !== null && ($student->tenant_id !== $tenant->id || ! $student->is_active)) {
            throw ValidationException::withMessages(['borrower' => 'მოსწავლე ვერ მოიძებნა.']);
        }

        $today = Carbon::now($tenant->timezone ?: 'Asia/Tbilisi')->startOfDay();

        if ($dueOn->lt($today) || $dueOn->gt($today->copy()->addDays(180))) {
            throw ValidationException::withMessages(['due_on' => 'დაბრუნების ვადა დღეიდან 180 დღემდე.']);
        }

        try {
            return DB::transaction(function () use ($tenant, $inventoryNumber, $student, $staffBorrower, $dueOn, $actor) {
                $copy = LibraryCopy::query()->where('tenant_id', $tenant->id)->where('inventory_number', trim($inventoryNumber))->lockForUpdate()->first();

                if ($copy === null) {
                    throw ValidationException::withMessages(['inventory_number' => 'ამ ნომრით ეგზემპლარი ვერ მოიძებნა.']);
                }

                $reservation = null;

                if ($copy->status === LibraryCopy::STATUS_ON_HOLD) {
                    $reservation = LibraryReservation::query()->where('tenant_id', $tenant->id)->where('library_copy_id', $copy->id)
                        ->where('status', LibraryReservation::STATUS_READY)->lockForUpdate()->first();

                    if ($reservation === null || $student === null || $reservation->student_id !== $student->id) {
                        throw ValidationException::withMessages(['inventory_number' => 'ეს ეგზემპლარი სხვა მოსწავლისთვისაა გადადებული.']);
                    }
                } elseif ($copy->status !== LibraryCopy::STATUS_AVAILABLE) {
                    throw ValidationException::withMessages(['inventory_number' => 'ეს ეგზემპლარი ახლა თავისუფალი არ არის.']);
                }

                $loan = new LibraryLoan([
                    'library_copy_id' => $copy->id,
                    'student_id' => $student?->id,
                    'user_id' => $staffBorrower?->id,
                    'due_on' => $dueOn->toDateString(),
                    'renewals' => 0,
                    'status' => LibraryLoan::STATUS_ACTIVE,
                    'active_key' => $copy->id,
                    'lent_by' => $actor->id,
                ]);
                $loan->tenant_id = $tenant->id;
                $loan->save();

                $copy->status = LibraryCopy::STATUS_ON_LOAN;
                $copy->save();

                if ($reservation !== null) {
                    $reservation->status = LibraryReservation::STATUS_FULFILLED;
                    $reservation->open_key = null;
                    $reservation->save();
                } elseif ($student !== null) {
                    // Borrowing the title directly also settles the student's own waiting reservation.
                    LibraryReservation::query()->where('tenant_id', $tenant->id)->where('library_resource_id', $copy->library_resource_id)
                        ->where('student_id', $student->id)->where('status', LibraryReservation::STATUS_WAITING)
                        ->update(['status' => LibraryReservation::STATUS_FULFILLED, 'open_key' => null]);
                }

                $this->auditLogger->record($tenant->id, 'library_loan.created', $loan, $actor->id, [
                    'copy' => $copy->inventory_number,
                    'student_id' => $student?->id,
                    'user_id' => $staffBorrower?->id,
                    'due_on' => $loan->due_on->toDateString(),
                ]);

                return $loan;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['inventory_number' => 'ეს ეგზემპლარი უკვე გაცემულია.']);
        }
    }

    public function returnLoan(LibraryLoan $loan, User $actor): void
    {
        DB::transaction(function () use ($loan, $actor) {
            $copy = LibraryCopy::query()->where('tenant_id', $loan->tenant_id)->whereKey($loan->library_copy_id)->lockForUpdate()->firstOrFail();
            $locked = LibraryLoan::query()->where('tenant_id', $loan->tenant_id)->whereKey($loan->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== LibraryLoan::STATUS_ACTIVE) {
                return;
            }

            $locked->status = LibraryLoan::STATUS_RETURNED;
            $locked->active_key = null;
            $locked->returned_at = Carbon::now();
            $locked->closed_by = $actor->id;
            $locked->save();

            $copy->status = LibraryCopy::STATUS_AVAILABLE;
            $copy->save();
            $heldFor = $this->releaseCopy($copy);

            $this->auditLogger->record($locked->tenant_id, 'library_loan.returned', $locked, $actor->id, [
                'copy' => $copy->inventory_number,
                'held_for_reservation' => $heldFor?->id,
            ]);
        });

        $loan->refresh();
    }

    public function renew(LibraryLoan $loan, User $actor): void
    {
        DB::transaction(function () use ($loan, $actor) {
            $locked = LibraryLoan::query()->where('tenant_id', $loan->tenant_id)->whereKey($loan->id)->lockForUpdate()->firstOrFail();
            $copy = LibraryCopy::query()->where('tenant_id', $loan->tenant_id)->whereKey($locked->library_copy_id)->firstOrFail();

            if ($locked->status !== LibraryLoan::STATUS_ACTIVE) {
                throw ValidationException::withMessages(['loan' => 'სესხი აქტიური აღარ არის.']);
            }

            if ($locked->renewals >= LibraryLoan::MAX_RENEWALS) {
                throw ValidationException::withMessages(['loan' => 'ვადა უკვე '.LibraryLoan::MAX_RENEWALS.'-ჯერ გაგრძელდა.']);
            }

            $waiting = LibraryReservation::query()->where('tenant_id', $loan->tenant_id)->where('library_resource_id', $copy->library_resource_id)
                ->where('status', LibraryReservation::STATUS_WAITING)->exists();

            if ($waiting) {
                throw ValidationException::withMessages(['loan' => 'ამ წიგნს სხვა მოსწავლე ელოდება — ვადა ვერ გაგრძელდება.']);
            }

            $tenant = Tenant::query()->findOrFail($locked->tenant_id);
            $today = Carbon::now($tenant->timezone ?: 'Asia/Tbilisi')->startOfDay();
            $from = $locked->due_on->lt($today) ? $today : $locked->due_on;
            $locked->due_on = $from->copy()->addDays(self::LOAN_DAYS);
            $locked->renewals += 1;
            $locked->reminder_sent_at = null;
            $locked->save();

            $this->auditLogger->record($locked->tenant_id, 'library_loan.renewed', $locked, $actor->id, ['due_on' => $locked->due_on->toDateString()]);
        });

        $loan->refresh();
    }

    public function markLost(LibraryLoan $loan, User $actor): void
    {
        DB::transaction(function () use ($loan, $actor) {
            $copy = LibraryCopy::query()->where('tenant_id', $loan->tenant_id)->whereKey($loan->library_copy_id)->lockForUpdate()->firstOrFail();
            $locked = LibraryLoan::query()->where('tenant_id', $loan->tenant_id)->whereKey($loan->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== LibraryLoan::STATUS_ACTIVE) {
                return;
            }

            $locked->status = LibraryLoan::STATUS_LOST;
            $locked->active_key = null;
            $locked->closed_by = $actor->id;
            $locked->save();

            $copy->status = LibraryCopy::STATUS_LOST;
            $copy->save();

            $this->auditLogger->record($locked->tenant_id, 'library_loan.lost', $locked, $actor->id, ['copy' => $copy->inventory_number]);
        });

        $loan->refresh();
    }

    public function withdrawCopy(LibraryCopy $copy, User $actor): void
    {
        DB::transaction(function () use ($copy, $actor) {
            $locked = LibraryCopy::query()->where('tenant_id', $copy->tenant_id)->whereKey($copy->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, [LibraryCopy::STATUS_ON_LOAN, LibraryCopy::STATUS_ON_HOLD], true)) {
                throw ValidationException::withMessages(['copy' => 'გაცემული ან გადადებული ეგზემპლარი ვერ ჩამოიწერება.']);
            }

            $locked->status = LibraryCopy::STATUS_WITHDRAWN;
            $locked->save();

            $this->auditLogger->record($locked->tenant_id, 'library_copy.withdrawn', $locked, $actor->id, ['copy' => $locked->inventory_number]);
        });

        $copy->refresh();
    }

    /**
     * Reserves a title for a student. If a copy is on the shelf it is held
     * at once; otherwise the student joins the queue.
     */
    public function reserve(LibraryResource $resource, Student $student, User $actor): LibraryReservation
    {
        if ($resource->access_scope !== LibraryResource::SCOPE_LOAN) {
            throw ValidationException::withMessages(['resource' => 'ეს წიგნი ბიბლიოთეკიდან არ გაიცემა.']);
        }

        if ($student->tenant_id !== $resource->tenant_id || ! $student->is_active) {
            throw ValidationException::withMessages(['student_id' => 'მოსწავლე ვერ მოიძებნა.']);
        }

        try {
            return DB::transaction(function () use ($resource, $student, $actor) {
                $copies = LibraryCopy::query()->where('tenant_id', $resource->tenant_id)->where('library_resource_id', $resource->id)
                    ->whereIn('status', [LibraryCopy::STATUS_AVAILABLE, LibraryCopy::STATUS_ON_LOAN, LibraryCopy::STATUS_ON_HOLD])
                    ->orderBy('id')->lockForUpdate()->get();

                if ($copies->isEmpty()) {
                    throw ValidationException::withMessages(['resource' => 'ამ წიგნის ეგზემპლარი ბიბლიოთეკაში არ არის.']);
                }

                $onLoanToStudent = LibraryLoan::query()->where('tenant_id', $resource->tenant_id)->where('student_id', $student->id)
                    ->where('status', LibraryLoan::STATUS_ACTIVE)->whereIn('library_copy_id', $copies->pluck('id'))->exists();

                if ($onLoanToStudent) {
                    throw ValidationException::withMessages(['resource' => 'ეს წიგნი მოსწავლეს უკვე აქვს გატანილი.']);
                }

                $reservation = new LibraryReservation([
                    'library_resource_id' => $resource->id,
                    'student_id' => $student->id,
                    'requested_by' => $actor->id,
                    'status' => LibraryReservation::STATUS_WAITING,
                    'open_key' => LibraryReservation::openKey($resource->tenant_id, $resource->id, $student->id),
                ]);
                $reservation->tenant_id = $resource->tenant_id;
                $reservation->save();

                $this->auditLogger->record($resource->tenant_id, 'library_reservation.created', $reservation, $actor->id, ['resource_id' => $resource->id, 'student_id' => $student->id]);

                $available = $copies->firstWhere('status', LibraryCopy::STATUS_AVAILABLE);

                if ($available !== null) {
                    $this->releaseCopy($available);
                }

                return $reservation->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['resource' => 'ეს წიგნი ამ მოსწავლისთვის უკვე დაჯავშნილია.']);
        }
    }

    public function cancelReservation(LibraryReservation $reservation, User $actor): void
    {
        DB::transaction(function () use ($reservation, $actor) {
            // Copy before reservation, like every other path (no deadlock).
            $heldCopyId = LibraryReservation::query()->where('tenant_id', $reservation->tenant_id)->whereKey($reservation->id)->value('library_copy_id');
            $copy = $heldCopyId === null ? null
                : LibraryCopy::query()->where('tenant_id', $reservation->tenant_id)->whereKey($heldCopyId)->lockForUpdate()->first();
            $locked = LibraryReservation::query()->where('tenant_id', $reservation->tenant_id)->whereKey($reservation->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [LibraryReservation::STATUS_WAITING, LibraryReservation::STATUS_READY], true)) {
                return;
            }

            $copyId = $locked->status === LibraryReservation::STATUS_READY ? $locked->library_copy_id : null;
            $locked->status = LibraryReservation::STATUS_CANCELLED;
            $locked->open_key = null;
            $locked->save();

            if ($copy !== null && $copyId === $copy->id && $copy->status === LibraryCopy::STATUS_ON_HOLD) {
                $copy->status = LibraryCopy::STATUS_AVAILABLE;
                $copy->save();
                $this->releaseCopy($copy);
            }

            $this->auditLogger->record($locked->tenant_id, 'library_reservation.cancelled', $locked, $actor->id, ['released_copy_id' => $copyId]);
        });

        $reservation->refresh();
    }

    /**
     * Ends holds whose pick-up time ran out and passes each copy on.
     * Returns how many expired. Runs without a request (scheduler).
     */
    public function expireHolds(): int
    {
        $expired = 0;
        $due = LibraryReservation::query()->withoutGlobalScope('tenant')
            ->where('status', LibraryReservation::STATUS_READY)->where('ready_until', '<', Carbon::now())->orderBy('id')->get();

        foreach ($due as $reservation) {
            $done = DB::transaction(function () use ($reservation) {
                $copy = $reservation->library_copy_id === null ? null
                    : LibraryCopy::query()->withoutGlobalScope('tenant')->whereKey($reservation->library_copy_id)->lockForUpdate()->first();
                $locked = LibraryReservation::query()->withoutGlobalScope('tenant')->whereKey($reservation->id)->lockForUpdate()->firstOrFail();

                if ($locked->status !== LibraryReservation::STATUS_READY || $locked->ready_until === null || $locked->ready_until->gte(Carbon::now())) {
                    return false;
                }

                $locked->status = LibraryReservation::STATUS_EXPIRED;
                $locked->open_key = null;
                $locked->save();

                if ($copy !== null && $copy->status === LibraryCopy::STATUS_ON_HOLD) {
                    $copy->status = LibraryCopy::STATUS_AVAILABLE;
                    $copy->save();
                    $this->releaseCopy($copy);
                }

                $this->auditLogger->record($locked->tenant_id, 'library_reservation.expired', $locked, null, ['copy_id' => $copy?->id]);

                return true;
            });

            $expired += $done ? 1 : 0;
        }

        return $expired;
    }

    /**
     * Gives an available, locked copy to the first waiting reservation for
     * its title (held for HOLD_DAYS, guardians notified after commit), or
     * leaves it on the shelf. Must run inside the caller's transaction.
     */
    private function releaseCopy(LibraryCopy $copy): ?LibraryReservation
    {
        if ($copy->status !== LibraryCopy::STATUS_AVAILABLE) {
            return null;
        }

        $next = LibraryReservation::query()->withoutGlobalScope('tenant')->where('tenant_id', $copy->tenant_id)
            ->where('library_resource_id', $copy->library_resource_id)->where('status', LibraryReservation::STATUS_WAITING)
            ->orderBy('id')->lockForUpdate()->first();

        if ($next === null) {
            return null;
        }

        $next->status = LibraryReservation::STATUS_READY;
        $next->library_copy_id = $copy->id;
        $next->ready_until = Carbon::now()->addDays(LibraryReservation::HOLD_DAYS);
        $next->save();

        $copy->status = LibraryCopy::STATUS_ON_HOLD;
        $copy->save();

        $this->notifyGuardians($next, LibraryNoticeMail::KIND_RESERVATION_READY);

        return $next;
    }

    /**
     * Queued after commit to guardians who receive notifications for the
     * student. Carries the title and dates only.
     */
    public function notifyGuardians(LibraryReservation|LibraryLoan $subject, string $kind): int
    {
        $student = Student::query()->withoutGlobalScope('tenant')->find($subject->student_id);

        if ($student === null) {
            return 0;
        }

        $tenant = Tenant::query()->findOrFail($subject->tenant_id);
        $tz = $tenant->timezone ?: 'Asia/Tbilisi';

        if ($subject instanceof LibraryReservation) {
            $title = LibraryResource::query()->withoutGlobalScope('tenant')->whereKey($subject->library_resource_id)->value('title');
            $date = $subject->ready_until?->copy()->setTimezone($tz)->format('d.m.Y');
        } else {
            $copy = LibraryCopy::query()->withoutGlobalScope('tenant')->with(['resource' => fn ($q) => $q->withoutGlobalScope('tenant')])->find($subject->library_copy_id);
            $title = $copy?->resource->title;
            $date = $subject->due_on->format('d.m.Y');
        }

        $emails = GuardianLink::query()->withoutGlobalScope('tenant')->where('tenant_id', $subject->tenant_id)->where('student_id', $student->id)
            ->where('is_active', true)->where('can_receive_notifications', true)
            ->with('guardian')->get()->map(fn (GuardianLink $link) => $link->guardian?->email)->filter()->unique();

        foreach ($emails as $email) {
            Mail::to($email)->queue((new LibraryNoticeMail($kind, $tenant->name, (string) $title, $student->fullName(), (string) $date))->afterCommit());
        }

        return $emails->count();
    }
}
