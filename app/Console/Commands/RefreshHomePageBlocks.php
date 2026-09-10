<?php

namespace App\Console\Commands;

use App\Domain\Content\HomePageBlueprint;
use App\Domain\Content\Models\Page;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Idempotently pushes HomePageBlueprint::blocks() onto an already-seeded
 * tenant's home page. TenantSeeder (local dev) isn't re-runnable — editing
 * its block literals does nothing for a database that's already seeded —
 * so this is the safe, re-runnable way to update the local "aisi" tenant's
 * home page after the block schema changes. (ProductionSeeder already
 * upserts pages via updateOrCreate, so re-running it has the same effect
 * in production — this command exists for the non-idempotent local seed.)
 */
class RefreshHomePageBlocks extends Command
{
    protected $signature = 'content:refresh-home {--tenant=aisi : Tenant slug whose home page should be refreshed}';

    protected $description = 'Idempotently update an existing tenant\'s home page blocks to the current HomePageBlueprint';

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
            ->where('slug', 'home')
            ->where('locale', $tenant->locale)
            ->first();

        if (! $page) {
            $this->error("Tenant \"{$slug}\" has no home page yet — this command only updates an existing one.");

            return self::FAILURE;
        }

        $page->blocks = HomePageBlueprint::blocks();
        $page->save();

        $this->info("Home page blocks refreshed for tenant \"{$slug}\".");

        return self::SUCCESS;
    }
}
