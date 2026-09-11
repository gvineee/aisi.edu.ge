<?php

namespace App\Console\Commands;

use App\Domain\Content\Actions\SyncContentMedia as SyncContentMediaAction;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Console entry point for {@see SyncContentMediaAction} (see
 * AGENT-REPORT-content-migration.md and docs/08-content-migration.md §6).
 * Defaults to dry-run; only --commit copies bytes into
 * storage/app/public/content-migration and writes Post.cover_image_path.
 *
 * Must run AFTER `content:import --commit` — it only acts on ContentImportRecord
 * rows already marked `imported`, and does nothing to the source
 * content-migration/cms-import.json data or draft status.
 */
class SyncContentMedia extends Command
{
    protected $signature = 'content:sync-media
        {--tenant= : Tenant slug to sync into (required, must already exist)}
        {--commit : Actually copy bytes into storage/app/public and write Post.cover_image_path. Without this flag, nothing is written.}
        {--limit= : Only process this many posts this run (bounded, resumable — omit for no limit)}';

    protected $description = 'Idempotent, dry-run-by-default copy of resolved legacy featured-image assets into tenant storage + Post.cover_image_path.';

    public function handle(SyncContentMediaAction $action): int
    {
        $tenantSlug = $this->option('tenant');

        if (! is_string($tenantSlug) || $tenantSlug === '') {
            $this->error('--tenant is required (a tenant slug). Refusing to guess a default tenant.');

            return self::FAILURE;
        }

        $tenant = Tenant::query()->where('slug', $tenantSlug)->first();

        if ($tenant === null) {
            $this->error("No tenant found with slug '{$tenantSlug}'. Refusing to guess a default tenant.");

            return self::FAILURE;
        }

        $commit = (bool) $this->option('commit');
        $limitOption = $this->option('limit');
        $limit = is_numeric($limitOption) ? (int) $limitOption : null;

        $this->info(sprintf(
            '%s tenant="%s"%s',
            $commit ? 'COMMIT run' : 'DRY RUN (no writes — pass --commit to apply)',
            $tenant->slug,
            $limit !== null ? " limit={$limit}" : '',
        ));

        $report = $action->run($tenant, $commit, $limit);

        if ($report === []) {
            $this->info('No imported posts with an unresolved/unsynced featured_media were found.');

            return self::SUCCESS;
        }

        $this->table(
            ['source_key', 'post_id', 'media_id', 'action', 'reason'],
            array_map(fn (array $row) => [
                $row['source_key'],
                $row['importable_id'],
                $row['featured_media_id'],
                $row['action'],
                Str::limit((string) $row['reason'], 90),
            ], $report),
        );

        $counts = [];
        foreach ($report as $row) {
            $counts[$row['action']] = ($counts[$row['action']] ?? 0) + 1;
        }

        $this->newLine();
        $this->info('Summary: '.collect($counts)->map(fn ($n, $action) => "{$action}={$n}")->implode(', '));

        return self::SUCCESS;
    }
}
