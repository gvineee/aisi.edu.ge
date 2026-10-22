<?php

namespace App\Console\Commands;

use App\Domain\Platform\Models\PlatformOperator;
use App\Domain\Platform\OperationsHealth;
use App\Mail\OperationsAlertMail;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Scheduled hourly (routes/console.php). E-mails the platform operators
 * (and OPS_ALERT_EMAIL, if set) when queued jobs failed since the last
 * alert or background work is unhealthy. Job class names and error first
 * lines only — never payloads, which can hold personal data. A dead
 * scheduler cannot report itself; `/up` covers that for an external monitor.
 */
class CheckOperations extends Command
{
    protected $signature = 'ops:check';

    protected $description = 'Alert platform operators about failed jobs or stalled background work';

    private const LAST_FAILED_ID_KEY = 'ops:last_alerted_failed_job_id';

    private const PROBLEMS_ALERTED_KEY = 'ops:problems_alerted';

    public function handle(OperationsHealth $health): int
    {
        $lastId = (int) Cache::get(self::LAST_FAILED_ID_KEY, 0);
        $failed = DB::table((string) config('queue.failed.table', 'failed_jobs'))->where('id', '>', $lastId)->orderBy('id')->get();
        $problems = $health->problems();
        // An ongoing problem is repeated at most every six hours.
        $reportProblems = $problems !== [] && Cache::add(self::PROBLEMS_ALERTED_KEY, true, now()->addHours(6));

        if ($failed->isEmpty() && ! $reportProblems) {
            $this->info('Operations healthy.');

            return self::SUCCESS;
        }

        $lines = $failed->map(function (object $job) {
            $payload = json_decode((string) $job->payload, true);
            $name = is_array($payload) && is_string($payload['displayName'] ?? null) ? $payload['displayName'] : 'unknown job';
            $error = strtok((string) $job->exception, "\n") ?: '';

            return "{$job->failed_at} — {$name}: ".mb_substr($error, 0, 200);
        })->all();

        $recipients = $this->recipients();

        foreach ($recipients as $email) {
            Mail::to($email)->send(new OperationsAlertMail((string) config('app.name'), $reportProblems ? $problems : [], $lines));
        }

        if ($failed->isNotEmpty()) {
            Cache::forever(self::LAST_FAILED_ID_KEY, (int) $failed->last()->id);
        }

        $this->warn('Alerted '.count($recipients).' recipient(s): '.count($lines).' failed job(s), '.count($problems).' problem(s).');

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function recipients(): array
    {
        $emails = User::query()
            ->whereIn('id', PlatformOperator::query()->where('is_active', true)->select('user_id'))
            ->pluck('email')->all();

        if (is_string($extra = config('mail.ops_alert_address')) && $extra !== '') {
            $emails[] = $extra;
        }

        return array_values(array_unique($emails));
    }
}
