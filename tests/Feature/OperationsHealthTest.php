<?php

namespace Tests\Feature;

use App\Domain\Platform\Models\PlatformOperator;
use App\Domain\Platform\OperationsHealth;
use App\Mail\OperationsAlertMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Background-work monitoring: `/up` fails on a stalled scheduler or an
 * unworked queue; ops:check e-mails operators about new failed jobs once.
 */
class OperationsHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_up_reflects_the_scheduler_heartbeat_and_the_queue(): void
    {
        // Never ran (fresh install / local): not a failure.
        $this->get('/up')->assertOk();

        app(OperationsHealth::class)->beat();
        $this->get('/up')->assertOk();

        $this->travel(OperationsHealth::STALE_AFTER_MINUTES + 1)->minutes();
        $this->get('/up')->assertServerError();

        app(OperationsHealth::class)->beat();
        $this->get('/up')->assertOk();

        config(['queue.default' => 'database']);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null,
            'available_at' => now()->subMinutes(OperationsHealth::QUEUE_BACKLOG_MINUTES + 5)->getTimestamp(), 'created_at' => now()->getTimestamp()]);
        $this->get('/up')->assertServerError();
    }

    public function test_new_failed_jobs_are_reported_once_without_payloads(): void
    {
        Mail::fake();
        $operator = User::factory()->create();
        PlatformOperator::create(['user_id' => $operator->id, 'is_active' => true]);
        $inactive = User::factory()->create();
        PlatformOperator::create(['user_id' => $inactive->id, 'is_active' => false]);
        config(['mail.ops_alert_address' => 'ops@example.test']);

        $this->artisan('ops:check')->assertSuccessful();
        Mail::assertNothingSent();

        DB::table('failed_jobs')->insert([
            'uuid' => 'b1d0c1a2-0000-4000-8000-000000000001', 'connection' => 'database', 'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Mail\\DocumentReviewReminderMail', 'data' => ['secret' => 'child-name']]),
            'exception' => "Symfony\\Component\\Mailer\\Exception\\TransportException: Connection refused\n#0 stack...", 'failed_at' => now(),
        ]);

        $this->artisan('ops:check')->assertSuccessful();
        Mail::assertSent(OperationsAlertMail::class, 2);
        Mail::assertSent(OperationsAlertMail::class, fn (OperationsAlertMail $mail) => $mail->hasTo($operator->email)
            && str_contains($mail->failedJobs[0], 'DocumentReviewReminderMail')
            && str_contains($mail->failedJobs[0], 'Connection refused')
            && ! str_contains(implode(' ', $mail->failedJobs), 'child-name'));
        Mail::assertNotSent(OperationsAlertMail::class, fn (OperationsAlertMail $mail) => $mail->hasTo($inactive->email));

        // Already reported.
        $this->artisan('ops:check')->assertSuccessful();
        Mail::assertSent(OperationsAlertMail::class, 2);
        $this->assertNotNull(Cache::get('ops:last_alerted_failed_job_id'));
    }
}
