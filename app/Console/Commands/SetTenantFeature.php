<?php

namespace App\Console\Commands;

use App\Domain\Governance\AuditLogger;
use App\Domain\Tenancy\Models\FeatureEntitlement;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Operator command to switch one module on or off for one tenant until the
 * platform administration UI (phase E) exists. Turning a feature off only
 * closes its routes (EnsureFeatureEnabled); data is never deleted.
 */
class SetTenantFeature extends Command
{
    protected $signature = 'tenant:feature
        {tenant : Tenant slug}
        {feature : One of the FeatureEntitlement::ALL names}
        {state : on|off}
        {--reason= : Recorded on the audit trail}';

    protected $description = 'Enable or disable a feature entitlement for a tenant (audited)';

    public function handle(AuditLogger $auditLogger): int
    {
        $tenant = Tenant::query()->where('slug', (string) $this->argument('tenant'))->first();
        $feature = (string) $this->argument('feature');
        $state = (string) $this->argument('state');

        if ($tenant === null) {
            $this->error('Tenant not found.');

            return self::FAILURE;
        }

        if (! in_array($feature, FeatureEntitlement::ALL, true) || ! in_array($state, ['on', 'off'], true)) {
            $this->error('Unknown feature or state. Features: '.implode(', ', FeatureEntitlement::ALL));

            return self::FAILURE;
        }

        $entitlement = FeatureEntitlement::query()->firstOrNew(['tenant_id' => $tenant->id, 'feature' => $feature]);
        $entitlement->is_enabled = $state === 'on';
        $entitlement->save();

        $auditLogger->record($tenant->id, 'feature_entitlement.changed', $entitlement, null, [
            'feature' => $feature,
            'enabled' => $entitlement->is_enabled,
            'reason' => $this->option('reason'),
            'via' => 'console',
        ]);

        $this->info("{$tenant->slug}: {$feature} = {$state}");

        return self::SUCCESS;
    }
}
