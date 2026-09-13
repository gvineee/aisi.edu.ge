<?php

namespace App\Console\Commands;

use App\Domain\Content\Models\Page;
use App\Domain\Content\SchoolLifePageBlueprint;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Idempotently pushes SchoolLifePageBlueprint::blocks() onto an
 * already-seeded tenant's school-life page — same pattern as
 * content:refresh-home and content:refresh-about.
 */
class RefreshSchoolLifePageBlocks extends Command
{
    protected $signature = 'content:refresh-school-life {--tenant=aisi : Tenant slug whose school-life page should be refreshed}';

    protected $description = 'Idempotently update an existing tenant\'s school-life page blocks to the current SchoolLifePageBlueprint';

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
            ->where('slug', 'school-life')
            ->where('locale', $tenant->locale)
            ->first();

        if (! $page) {
            $this->error("Tenant \"{$slug}\" has no school-life page yet — this command only updates an existing one.");

            return self::FAILURE;
        }

        $page->blocks = SchoolLifePageBlueprint::blocks();
        $page->save();

        $this->info("School-life page blocks refreshed for tenant \"{$slug}\".");

        return self::SUCCESS;
    }
}
