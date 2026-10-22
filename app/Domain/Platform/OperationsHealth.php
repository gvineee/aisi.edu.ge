<?php

namespace App\Domain\Platform;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Background-work health (docs/security-asvs-checklist.md, Operations).
 * The host has no supervisor: cron runs the scheduler, which also drains
 * the database queue. If cron stops, reminders, invoices and every queued
 * e-mail silently stop with it. The scheduler writes a heartbeat each
 * minute; `/up` fails when it goes stale or when queued jobs sit unworked,
 * so an external uptime monitor on `/up` notices what no one in-app would.
 */
class OperationsHealth
{
    public const HEARTBEAT_KEY = 'ops:scheduler_heartbeat';

    public const STALE_AFTER_MINUTES = 10;

    public const QUEUE_BACKLOG_MINUTES = 15;

    public function beat(): void
    {
        Cache::forever(self::HEARTBEAT_KEY, Carbon::now()->getTimestamp());
    }

    /**
     * Human-readable problems; empty when healthy. A missing heartbeat is not
     * a problem (fresh install, local dev, cache just cleared) — only one
     * that stopped advancing is.
     *
     * @return array<int, string>
     */
    public function problems(): array
    {
        $problems = [];
        $heartbeat = Cache::get(self::HEARTBEAT_KEY);

        if (is_int($heartbeat) && $heartbeat < Carbon::now()->subMinutes(self::STALE_AFTER_MINUTES)->getTimestamp()) {
            $problems[] = 'scheduler stalled since '.Carbon::createFromTimestamp($heartbeat)->toIso8601String();
        }

        $backlog = $this->queueBacklog();

        if ($backlog > 0) {
            $problems[] = "{$backlog} queued job(s) waiting over ".self::QUEUE_BACKLOG_MINUTES.' minutes';
        }

        return $problems;
    }

    private function queueBacklog(): int
    {
        if (config('queue.default') !== 'database') {
            return 0;
        }

        $table = (string) config('queue.connections.database.table', 'jobs');

        return DB::table($table)->whereNull('reserved_at')
            ->where('available_at', '<', Carbon::now()->subMinutes(self::QUEUE_BACKLOG_MINUTES)->getTimestamp())
            ->count();
    }
}
