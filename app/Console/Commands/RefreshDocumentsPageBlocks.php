<?php

namespace App\Console\Commands;

use App\Domain\Content\DocumentsPageBlueprint;
use App\Domain\Content\Models\Page;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Idempotently creates or updates the tenant's "documents" page from
 * DocumentsPageBlueprint::blocks() — same pattern as content:refresh-home
 * and content:refresh-about, but this page may not exist yet at all on an
 * already-seeded database (it's new), so this uses updateOrCreate rather
 * than requiring the page to already exist.
 */
class RefreshDocumentsPageBlocks extends Command
{
    protected $signature = 'content:refresh-documents {--tenant=aisi : Tenant slug whose documents page should be refreshed}';

    protected $description = 'Idempotently create or update an existing tenant\'s documents page to the current DocumentsPageBlueprint';

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
            ->where('slug', 'official-documents')
            ->where('locale', $tenant->locale)
            ->first();

        $tenant->pages()->updateOrCreate(
            ['slug' => 'official-documents', 'locale' => $tenant->locale],
            [
                'title' => 'დოკუმენტები',
                'excerpt' => 'ფინანსური ანგარიშები, სამოქმედო გეგმები და შიდა რეგულაციები.',
                'blocks' => DocumentsPageBlueprint::blocks(),
                'status' => Page::STATUS_PUBLISHED,
                'published_at' => $existing->published_at ?? Carbon::now(),
                'seo_title' => 'ოფიციალური დოკუმენტები — აისი',
                'seo_description' => 'სკოლა აისის ფინანსური ანგარიშები, სამოქმედო გეგმები და შიდა რეგულაციები.',
                'created_by' => $existing->created_by ?? $admin->id,
                'updated_by' => $admin->id,
            ],
        );

        $this->info("Documents page refreshed for tenant \"{$slug}\".");

        return self::SUCCESS;
    }
}
