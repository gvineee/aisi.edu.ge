<?php

namespace App\Console\Commands;

use App\Domain\Content\AdmissionsPageBlueprint;
use App\Domain\Content\Models\Page;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Idempotently creates or updates the tenant's "admissions" page from
 * AdmissionsPageBlueprint::blocks() — same pattern as
 * content:refresh-documents (this page is also new, so updateOrCreate
 * rather than requiring it to already exist).
 */
class RefreshAdmissionsPageBlocks extends Command
{
    protected $signature = 'content:refresh-admissions {--tenant=aisi : Tenant slug whose admissions page should be refreshed}';

    protected $description = 'Idempotently create or update an existing tenant\'s admissions page to the current AdmissionsPageBlueprint';

    public function handle(): int
    {
        $slug = (string) $this->option('tenant');
        $tenant = Tenant::query()->where('slug', $slug)->first();

        if (! $tenant) {
            $this->error("No tenant found with slug \"{$slug}\".");

            return self::FAILURE;
        }

        $actorMembership = TenantMembership::query()
            ->where('tenant_id', $tenant->id)
            ->where('role', TenantMembership::ROLE_ADMIN)
            ->where('is_active', true)
            ->first();

        $admin = $actorMembership ? User::find($actorMembership->user_id) : null;

        if (! $admin) {
            $this->error("No active admin membership found for tenant \"{$slug}\" to attribute this change to.");

            return self::FAILURE;
        }

        $existing = Page::query()
            ->where('tenant_id', $tenant->id)
            ->where('slug', 'admissions')
            ->where('locale', $tenant->locale)
            ->first();

        $tenant->pages()->updateOrCreate(
            ['slug' => 'admissions', 'locale' => $tenant->locale],
            [
                'title' => 'მიღება',
                'excerpt' => 'ჩარიცხვის წესი და ვიზიტის დაგეგმვა.',
                'blocks' => AdmissionsPageBlueprint::blocks(),
                'status' => Page::STATUS_PUBLISHED,
                'published_at' => $existing->published_at ?? Carbon::now(),
                'seo_title' => 'მიღება — აისი',
                'seo_description' => 'გაეცანით სკოლა აისის ჩარიცხვის წესს და დაგეგმეთ ვიზიტი.',
                'created_by' => $existing->created_by ?? $admin->id,
                'updated_by' => $admin->id,
            ],
        );

        $this->info("Admissions page refreshed for tenant \"{$slug}\".");

        return self::SUCCESS;
    }
}
