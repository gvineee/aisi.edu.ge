<?php

namespace App\Console\Commands;

use App\Domain\Finance\Actions\ManageFeeBilling;
use App\Domain\Tenancy\Models\FeatureEntitlement;
use Illuminate\Console\Command;

/**
 * Daily: issues invoices for fee-plan installments whose issue date has
 * arrived, for every tenant with the finance feature on. Idempotent — an
 * installment already billed for a contract is never billed again.
 */
class GenerateFeeInvoices extends Command
{
    protected $signature = 'finance:generate-fee-invoices {--dry-run : Only count what would be issued}';

    protected $description = 'Issue invoices for due fee-plan installments';

    public function handle(ManageFeeBilling $billing): int
    {
        $tenantIds = FeatureEntitlement::query()
            ->where('feature', FeatureEntitlement::FEATURE_FINANCE)->where('is_enabled', true)->pluck('tenant_id');

        foreach ($tenantIds as $tenantId) {
            $result = $billing->generate((int) $tenantId, null, (bool) $this->option('dry-run'), null);
            $this->line("tenant {$tenantId}: invoices={$result['invoices']} waived={$result['waived']} total_minor={$result['totalMinor']} skipped_inactive={$result['skippedInactive']}");
        }

        return self::SUCCESS;
    }
}
