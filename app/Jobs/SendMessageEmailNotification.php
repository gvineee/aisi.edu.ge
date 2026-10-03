<?php

namespace App\Jobs;

use App\Domain\Communications\Models\MessageDelivery;
use App\Mail\NewMessageNotificationMail;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends one email notification for one delivery row. Idempotent: the row
 * is claimed (queued → sending) atomically, so a retry or duplicate job
 * never mails twice; retried with backoff on failure.
 */
class SendMessageEmailNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly int $deliveryId,
        public readonly string $schoolName,
        public readonly string $subject,
        public readonly string $url,
    ) {
        $this->afterCommit();
    }

    public function handle(): void
    {
        $claimed = DB::table('message_deliveries')->where('id', $this->deliveryId)
            ->whereIn('status', ['queued', 'failed'])
            ->update(['status' => 'sending', 'attempts' => DB::raw('attempts + 1')]);

        if ($claimed !== 1) {
            return; // already sent or being sent
        }

        $delivery = MessageDelivery::query()->withoutGlobalScopes()->findOrFail($this->deliveryId);
        $recipient = User::query()->findOrFail($delivery->recipient_id);

        try {
            Mail::to($recipient->email)->send(new NewMessageNotificationMail($this->schoolName, $this->subject, $this->url));
            $delivery->forceFill(['status' => 'sent', 'delivered_at' => now(), 'failure_reason' => null])->save();
        } catch (Throwable $e) {
            $delivery->forceFill(['status' => 'failed', 'failure_reason' => mb_substr(class_basename($e), 0, 255)])->save();

            throw $e;
        }
    }
}
