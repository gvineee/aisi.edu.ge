<?php

namespace App\Console\Commands;

use App\Domain\Content\Actions\ChangePageStatus;
use App\Domain\Content\Actions\SavePage;
use App\Domain\Content\LegacyMenuPages;
use App\Domain\Content\Models\Page;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Publishes the previous site's menu pages (LegacyMenuPages) so the public
 * menu shows them the way the old site did. Goes through the CMS's own
 * SavePage and ChangePageStatus, so every change leaves a page revision and
 * an audit event attributed to the tenant's admin, and an editor can
 * restore the imported version or unpublish a page afterwards.
 *
 * Idempotent: a page already in the target layout is not saved again, and
 * a page that does not exist for the tenant is reported and skipped.
 * `--dry-run` only reports.
 */
class PublishLegacyMenuPages extends Command
{
    protected $signature = 'content:publish-menu-pages {--tenant=aisi : Tenant slug} {--dry-run : Report what would change without saving}';

    protected $description = 'Lay out and publish the previous site\'s menu pages so the public menu links them';

    public function handle(SavePage $savePage, ChangePageStatus $changePageStatus): int
    {
        $slug = (string) $this->option('tenant');
        $tenant = Tenant::query()->where('slug', $slug)->first();

        if (! $tenant) {
            $this->error("No tenant found with slug \"{$slug}\".");

            return self::FAILURE;
        }

        $membership = TenantMembership::query()
            ->where('tenant_id', $tenant->id)
            ->where('role', TenantMembership::ROLE_ADMIN)
            ->where('is_active', true)
            ->first();
        $admin = $membership ? User::find($membership->user_id) : null;

        if (! $admin) {
            $this->error("No active admin membership found for tenant \"{$slug}\" to attribute this change to.");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        foreach (array_keys(LegacyMenuPages::PAGES) as $pageSlug) {
            $page = Page::query()
                ->where('tenant_id', $tenant->id)
                ->where('slug', $pageSlug)
                ->where('locale', $tenant->locale)
                ->first();

            if (! $page) {
                $this->warn("{$pageSlug}: not imported for this tenant, skipped.");

                continue;
            }

            $blocks = $this->isLaidOut($page)
                ? $page->blocks
                : LegacyMenuPages::blocks($pageSlug, $page->title, $page->blocks ?? []);

            if ($blocks === null) {
                $this->warn("{$pageSlug}: no usable text, left as {$page->status}.");

                continue;
            }

            $changesBlocks = $blocks !== $page->blocks;
            $publishes = ! $page->isPublished();

            $this->line(sprintf('%s „%s“: %s', $pageSlug, $page->title, match (true) {
                $changesBlocks && $publishes => 'new layout, published',
                $changesBlocks => 'new layout',
                $publishes => 'published',
                default => 'unchanged',
            }));

            if ($dryRun) {
                continue;
            }

            if ($changesBlocks) {
                $page = $savePage->handle($tenant->id, $page, [
                    'slug' => $page->slug,
                    'locale' => $page->locale,
                    'title' => $page->title,
                    'excerpt' => $page->excerpt,
                    'blocks' => $blocks,
                    'seo_title' => $page->seo_title,
                    'seo_description' => $page->seo_description,
                ], $admin);
            }

            if ($publishes) {
                $changePageStatus->handle($page, true, $admin);
            }
        }

        if ($dryRun) {
            $this->info('Dry run: nothing was saved.');
        }

        return self::SUCCESS;
    }

    /** An editor may have changed a page after it was laid out: keep their version. */
    private function isLaidOut(Page $page): bool
    {
        return ($page->blocks[0]['type'] ?? null) === 'hero';
    }
}
