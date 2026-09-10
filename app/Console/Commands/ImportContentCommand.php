<?php

namespace App\Console\Commands;

use App\Domain\Content\Actions\ImportContentRecords;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Console entry point for the legacy WordPress -> CMS importer
 * (docs/08-content-migration.md §6). Defaults to dry-run; only --commit
 * writes to the database, and every created record is a draft.
 */
class ImportContentCommand extends Command
{
    protected $signature = 'content:import
        {--tenant= : Tenant slug to import into (required, must already exist)}
        {--commit : Actually write to the database. Without this flag, nothing is written.}
        {--only=pages,posts : Comma-separated source types to import}';

    protected $description = 'Idempotent, dry-run-by-default import of content-migration/cms-import.json into draft Pages/Posts.';

    public function handle(ImportContentRecords $importer): int
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

        $only = array_filter(array_map('trim', explode(',', (string) $this->option('only'))));
        $commit = (bool) $this->option('commit');

        $importPath = base_path('content-migration/cms-import.json');
        $failuresPath = base_path('content-migration/failures.json');

        if (! is_file($importPath)) {
            $this->error("Missing {$importPath} — run scripts/collect-public-content.py + scripts/prepare-school-content.py first (not this command's job to fetch it).");

            return self::FAILURE;
        }

        $import = json_decode((string) file_get_contents($importPath), true);
        $records = is_array($import) && is_array($import['records'] ?? null) ? $import['records'] : [];

        $failedUrls = [];
        if (is_file($failuresPath)) {
            $failures = json_decode((string) file_get_contents($failuresPath), true);
            if (is_array($failures)) {
                foreach ($failures as $failure) {
                    if (is_array($failure) && is_string($failure['url'] ?? null)) {
                        $failedUrls[] = $failure['url'];
                    }
                }
            }
        }

        $batchId = (string) Str::uuid();

        $this->info(sprintf(
            '%s tenant="%s" only=%s batch=%s (%d source records loaded)',
            $commit ? 'COMMIT run' : 'DRY RUN (no writes — pass --commit to apply)',
            $tenant->slug,
            implode(',', $only),
            $batchId,
            count($records),
        ));

        $report = $importer->run($tenant, $records, $only, $failedUrls, $batchId, $commit);

        $counts = [];
        foreach ($report as $row) {
            $counts[$row['action']] = ($counts[$row['action']] ?? 0) + 1;
        }

        $this->table(
            ['key', 'type', 'title', 'action', 'reason'],
            array_map(fn (array $row) => [
                $row['key'],
                $row['type'],
                Str::limit((string) $row['title'], 40),
                $row['action'],
                Str::limit((string) $row['reason'], 70),
            ], $report),
        );

        $this->newLine();
        $this->info('Summary: '.collect($counts)->map(fn ($n, $action) => "{$action}={$n}")->implode(', '));

        $flagged = array_filter($report, fn (array $row) => $row['merge_group'] !== null);
        if ($flagged !== []) {
            $this->newLine();
            $this->warn('Merge candidates (never auto-merged — pick the canonical one by hand):');
            foreach ($flagged as $row) {
                $this->line("  [{$row['merge_group']}] {$row['key']} — {$row['title']}");
            }
        }

        return self::SUCCESS;
    }
}
