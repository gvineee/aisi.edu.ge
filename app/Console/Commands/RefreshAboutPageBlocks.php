<?php

namespace App\Console\Commands;

use App\Domain\Content\AboutPageBlueprint;
use App\Domain\Content\Models\Page;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Idempotently pushes AboutPageBlueprint::blocks() onto an already-seeded
 * tenant's about page — the same pattern as content:refresh-home, needed
 * because neither TenantSeeder's create() calls nor a production database
 * already past its first seed can be updated by editing the seeder alone.
 */
class RefreshAboutPageBlocks extends Command
{
    protected $signature = 'content:refresh-about {--tenant=aisi : Tenant slug whose about page should be refreshed}';

    protected $description = 'Idempotently update an existing tenant\'s about page blocks to the current AboutPageBlueprint';

    public function handle(): int
    {
        $slug = (string) $this->option('tenant');
        $tenant = Tenant::query()->where('slug', $slug)->first();

        if (! $tenant) {
            $this->error("No tenant found with slug \"{$slug}\".");

            return self::FAILURE;
        }

        $page = Page::query()
            ->where('tenant_id', $tenant->id)
            ->where('slug', 'about')
            ->where('locale', $tenant->locale)
            ->first();

        if (! $page) {
            $this->error("Tenant \"{$slug}\" has no about page yet — this command only updates an existing one.");

            return self::FAILURE;
        }

        $page->blocks = AboutPageBlueprint::blocks();
        $page->save();

        $this->info("About page blocks refreshed for tenant \"{$slug}\".");

        return self::SUCCESS;
    }
}
