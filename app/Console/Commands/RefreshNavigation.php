<?php

namespace App\Console\Commands;

use App\Domain\Content\NavigationBlueprint;
use App\Domain\Tenancy\Models\BrandSetting;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Idempotently writes NavigationBlueprint::definition() to a tenant's brand
 * settings — the same pattern as content:refresh-home. `--reset` removes
 * the tenant's own menu so the generic one applies again.
 */
class RefreshNavigation extends Command
{
    protected $signature = 'content:refresh-navigation {--tenant=aisi : Tenant slug whose public menu should be refreshed} {--reset : Remove the tenant menu and use the generic one}';

    protected $description = 'Idempotently set a tenant\'s public menu to the current NavigationBlueprint';

    public function handle(): int
    {
        $slug = (string) $this->option('tenant');
        $tenant = Tenant::query()->where('slug', $slug)->first();

        if (! $tenant) {
            $this->error("No tenant found with slug \"{$slug}\".");

            return self::FAILURE;
        }

        $brand = BrandSetting::query()->where('tenant_id', $tenant->id)->first();

        if (! $brand) {
            $this->error("Tenant \"{$slug}\" has no brand settings yet.");

            return self::FAILURE;
        }

        $brand->navigation = $this->option('reset') ? null : NavigationBlueprint::definition();
        $brand->save();

        $this->info("Public menu refreshed for tenant \"{$slug}\".");

        return self::SUCCESS;
    }
}
