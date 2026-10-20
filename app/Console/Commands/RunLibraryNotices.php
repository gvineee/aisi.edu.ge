<?php

namespace App\Console\Commands;

use App\Domain\Library\Actions\ManageLibraryCirculation;
use App\Domain\Library\Models\LibraryLoan;
use App\Domain\Tenancy\Models\FeatureEntitlement;
use App\Domain\Tenancy\Models\Tenant;
use App\Mail\LibraryNoticeMail;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Scheduled hourly (routes/console.php). Expires reservation holds whose
 * pick-up time ran out (the copy goes to the next in line), and reminds
 * guardians of overdue student loans — at most once every three days per
 * loan, claimed with a conditional update so overlapping runs never send
 * twice. Only tenants with the library feature switched on.
 */
class RunLibraryNotices extends Command
{
    protected $signature = 'library:run-notices';

    protected $description = 'Expire library holds and remind guardians of overdue books';

    public function handle(ManageLibraryCirculation $circulation): int
    {
        $expired = $circulation->expireHolds();
        $reminded = 0;

        $tenantIds = FeatureEntitlement::query()->where('feature', FeatureEntitlement::FEATURE_LIBRARY)->where('is_enabled', true)->pluck('tenant_id');

        foreach (Tenant::query()->whereIn('id', $tenantIds)->get() as $tenant) {
            $today = Carbon::now($tenant->timezone ?: 'Asia/Tbilisi')->toDateString();
            $loans = LibraryLoan::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)
                ->where('status', LibraryLoan::STATUS_ACTIVE)->whereNotNull('student_id')
                ->whereDate('due_on', '<', $today)
                ->where(fn ($q) => $q->whereNull('reminder_sent_at')->orWhere('reminder_sent_at', '<', Carbon::now()->subDays(3)))
                ->get();

            foreach ($loans as $loan) {
                $claimed = LibraryLoan::query()->withoutGlobalScope('tenant')->whereKey($loan->id)
                    ->where(fn ($q) => $q->whereNull('reminder_sent_at')->orWhere('reminder_sent_at', '<', Carbon::now()->subDays(3)))
                    ->update(['reminder_sent_at' => Carbon::now()]);

                if ($claimed === 1) {
                    $reminded += $circulation->notifyGuardians($loan, LibraryNoticeMail::KIND_OVERDUE) > 0 ? 1 : 0;
                }
            }
        }

        $this->info("Expired holds: {$expired}; overdue loans reminded: {$reminded}.");

        return self::SUCCESS;
    }
}
