<?php

namespace App\Console\Commands;

use App\Domain\Communications\Models\AppointmentBooking;
use App\Domain\Communications\Models\AppointmentSlot;
use App\Domain\Tenancy\Models\Tenant;
use App\Mail\AppointmentReminderMail;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * Scheduled hourly (routes/console.php). Reminds the guardian and the
 * teacher of each booked meeting starting within the window. Runs outside
 * any request, so it reads across tenants explicitly and renders the time
 * in each booking's own tenant timezone. `reminder_sent_at` is claimed
 * with a conditional update, so overlapping runs never send twice.
 */
class SendAppointmentReminders extends Command
{
    protected $signature = 'appointments:send-reminders {--hours=24 : Remind meetings starting within this many hours}';

    protected $description = 'Queue reminder e-mails for upcoming parent–teacher meetings';

    public function handle(): int
    {
        $now = Carbon::now();
        $until = $now->copy()->addHours(max(1, (int) $this->option('hours')));
        $sent = 0;

        $bookings = AppointmentBooking::query()->withoutGlobalScope('tenant')
            ->where('status', AppointmentBooking::STATUS_BOOKED)
            ->whereNull('reminder_sent_at')
            ->whereHas('slot', fn ($query) => $query->withoutGlobalScope('tenant')
                ->where('status', AppointmentSlot::STATUS_OPEN)
                ->where('starts_at', '>', $now)->where('starts_at', '<=', $until))
            ->with(['slot' => fn ($query) => $query->withoutGlobalScope('tenant')->with('teacher'), 'guardian'])
            ->get();

        $tenants = Tenant::query()->whereIn('id', $bookings->pluck('tenant_id')->unique())->get()->keyBy('id');

        foreach ($bookings as $booking) {
            $claimed = AppointmentBooking::query()->withoutGlobalScope('tenant')->whereKey($booking->id)
                ->whereNull('reminder_sent_at')->update(['reminder_sent_at' => Carbon::now()]);

            if ($claimed !== 1) {
                continue;
            }

            $tenant = $tenants->get($booking->tenant_id);
            $slot = $booking->slot;
            $when = $slot->starts_at->copy()->setTimezone($tenant->timezone ?? 'Asia/Tbilisi')->format('d.m.Y H:i');
            $school = $tenant->name ?? '';

            if ($booking->guardian !== null) {
                Mail::to($booking->guardian)->queue(new AppointmentReminderMail($school, $slot->teacher->name ?? '', $when, $slot->location));
            }

            if ($slot->teacher !== null) {
                Mail::to($slot->teacher)->queue(new AppointmentReminderMail($school, $booking->guardian->name ?? '', $when, $slot->location));
            }

            $sent++;
        }

        $this->info("Reminders queued for {$sent} booking(s).");

        return self::SUCCESS;
    }
}
