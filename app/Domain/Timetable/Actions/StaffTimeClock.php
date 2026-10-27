<?php

namespace App\Domain\Timetable\Actions;

use App\Domain\Governance\AuditLogger;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Timetable\Models\StaffTimeEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Clocking in and out, management's corrections, and the monthly summary.
 * A person has at most one open entry; a forgotten clock-out stays open
 * until it is corrected, and an open entry counts no hours.
 */
class StaffTimeClock
{
    /** An entry longer than this is a forgotten clock-out, not a workday. */
    private const MAX_MINUTES = 16 * 60;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function open(int $tenantId, int $userId): ?StaffTimeEntry
    {
        return StaffTimeEntry::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->whereNull('clock_out_at')->latest('clock_in_at')->first();
    }

    public function isStale(StaffTimeEntry $entry): bool
    {
        return $entry->clock_out_at === null && $entry->clock_in_at->diffInMinutes(now()) > self::MAX_MINUTES;
    }

    public function clockIn(Tenant $tenant, User $user, ?string $note): StaffTimeEntry
    {
        $open = $this->open($tenant->id, $user->id);

        // A stale open entry (forgotten clock-out) waits for a correction
        // and does not block today's arrival.
        if ($open !== null && $open->clock_in_at->diffInMinutes(now()) <= self::MAX_MINUTES) {
            throw ValidationException::withMessages(['clock' => 'უკვე მონიშნული გაქვთ მოსვლა — ჯერ მონიშნეთ წასვლა.']);
        }

        $entry = new StaffTimeEntry([
            'user_id' => $user->id,
            'work_date' => Carbon::now($tenant->timezone)->toDateString(),
            'clock_in_at' => now(),
            'source' => 'self',
            'note' => $note,
        ]);
        $entry->tenant_id = $tenant->id;
        $entry->save();

        return $entry;
    }

    public function clockOut(Tenant $tenant, User $user): StaffTimeEntry
    {
        $entry = $this->open($tenant->id, $user->id);

        if ($entry === null) {
            throw ValidationException::withMessages(['clock' => 'მოსვლა მონიშნული არ არის.']);
        }

        if ($entry->clock_in_at->diffInMinutes(now()) > self::MAX_MINUTES) {
            throw ValidationException::withMessages(['clock' => 'წინა მოსვლა 16 საათზე მეტი ხნის წინაა — წასვლის დროს ადმინისტრაცია შეასწორებს. ახლა შეგიძლიათ ახალი მოსვლა მონიშნოთ.']);
        }

        $entry->update(['clock_out_at' => now()]);

        return $entry;
    }

    /**
     * Management sets an entry's times (new or existing) with a reason.
     */
    public function correct(Tenant $tenant, ?StaffTimeEntry $entry, int $userId, Carbon $in, ?Carbon $out, string $reason, User $actor): StaffTimeEntry
    {
        if ($out !== null && $out->lessThanOrEqualTo($in)) {
            throw ValidationException::withMessages(['clock_out_at' => 'წასვლა მოსვლის შემდეგ უნდა იყოს.']);
        }

        if ($out !== null && $in->diffInMinutes($out) > self::MAX_MINUTES) {
            throw ValidationException::withMessages(['clock_out_at' => 'ერთი ჩანაწერი 16 საათზე გრძელი ვერ იქნება.']);
        }

        $before = $entry?->only(['clock_in_at', 'clock_out_at']);
        $entry ??= tap(new StaffTimeEntry(['user_id' => $userId, 'source' => 'manual']), fn (StaffTimeEntry $e) => $e->tenant_id = $tenant->id);
        $entry->fill([
            'work_date' => $in->copy()->timezone($tenant->timezone)->toDateString(),
            'clock_in_at' => $in->copy()->utc(),
            'clock_out_at' => $out?->copy()->utc(),
            'corrected_by' => $actor->id,
            'corrected_at' => now(),
            'correction_reason' => $reason,
        ])->save();

        $this->auditLogger->record($tenant->id, $before === null ? 'time_clock.entry_added' : 'time_clock.entry_corrected', $entry, $actor->id, [
            'user_id' => $entry->user_id,
            'before' => $before === null ? null : array_map(fn ($value) => $value instanceof Carbon ? $value->toIso8601String() : $value, $before),
        ]);

        return $entry;
    }

    /**
     * Per person for the range: days with an entry, closed minutes, open
     * (unfinished) entries.
     *
     * @return list<array{userId: int, days: int, minutes: int, open: int}>
     */
    public function summary(int $tenantId, Carbon $from, Carbon $to): array
    {
        return array_values(StaffTimeEntry::query()->where('tenant_id', $tenantId)
            ->whereDate('work_date', '>=', $from->toDateString())->whereDate('work_date', '<=', $to->toDateString())
            ->get()->groupBy('user_id')
            ->map(fn (Collection $entries, int $userId) => [
                'userId' => $userId,
                'days' => $entries->map(fn (StaffTimeEntry $e) => $e->work_date->toDateString())->unique()->count(),
                'minutes' => (int) $entries->sum(fn (StaffTimeEntry $e) => $e->minutes() ?? 0),
                'open' => $entries->whereNull('clock_out_at')->count(),
            ])->all());
    }
}
