<?php

namespace App\Console\Commands;

use App\Domain\Admissions\Actions\ManageSchoolVisits;
use App\Domain\Admissions\Models\AdmissionVisitBooking;
use App\Domain\Admissions\Models\AdmissionVisitSlot;
use App\Domain\Tenancy\Models\Tenant;
use App\Mail\SchoolVisitMail;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * Scheduled hourly (routes/console.php). Reminds families with an e-mail
 * contact of a school visit starting within the window. Reads across
 * tenants explicitly; reminder_sent_at is claimed with a conditional
 * update, so overlapping runs never send twice.
 */
class SendSchoolVisitReminders extends Command
{
    protected $signature = 'admissions:send-visit-reminders {--hours=24 : Remind visits starting within this many hours}';

    protected $description = 'Queue reminder e-mails for upcoming school visits';

    public function handle(ManageSchoolVisits $visits): int
    {
        $now = Carbon::now();
        $until = $now->copy()->addHours(max(1, (int) $this->option('hours')));
        $sent = 0;

        $bookings = AdmissionVisitBooking::query()->withoutGlobalScope('tenant')
            ->where('status', AdmissionVisitBooking::STATUS_BOOKED)
            ->whereNotNull('notify_email')
            ->whereNull('reminder_sent_at')
            ->whereHas('slot', fn ($query) => $query->withoutGlobalScope('tenant')
                ->where('status', AdmissionVisitSlot::STATUS_OPEN)
                ->where('starts_at', '>', $now)->where('starts_at', '<=', $until))
            ->with(['slot' => fn ($query) => $query->withoutGlobalScope('tenant')])
            ->get();

        $tenants = Tenant::query()->whereIn('id', $bookings->pluck('tenant_id')->unique())->get()->keyBy('id');

        foreach ($bookings as $booking) {
            $claimed = AdmissionVisitBooking::query()->withoutGlobalScope('tenant')->whereKey($booking->id)
                ->whereNull('reminder_sent_at')->update(['reminder_sent_at' => Carbon::now()]);
            $tenant = $tenants->get($booking->tenant_id);

            if ($claimed !== 1 || $tenant === null) {
                continue;
            }

            Mail::to((string) $booking->notify_email)->queue(new SchoolVisitMail(
                SchoolVisitMail::KIND_REMINDER, $tenant->name, $visits->when($tenant, $booking->slot), $booking->slot->location,
            ));
            $sent++;
        }

        $this->info("Visit reminders queued for {$sent} booking(s).");

        return self::SUCCESS;
    }
}
