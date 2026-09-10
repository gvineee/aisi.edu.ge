<?php

namespace App\Console\Commands;

use App\Domain\Content\Actions\ImportLibraryLinks;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Console entry point for importing content-migration/library-catalog.json
 * (external book/resource links scraped from the old site's /research/*
 * archive pages) into the real LibraryResource catalog. Dry-run by default;
 * only --commit writes to the database.
 */
class ImportLibraryLinksCommand extends Command
{
    protected $signature = 'content:import-library
        {--tenant= : Tenant slug to import into (required, must already exist)}
        {--commit : Actually write to the database. Without this flag, nothing is written.}';

    protected $description = 'Idempotent, dry-run-by-default import of content-migration/library-catalog.json into LibraryResource rows (external links only, never re-hosted files).';

    public function handle(ImportLibraryLinks $importer): int
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

        $path = base_path('content-migration/library-catalog.json');

        if (! is_file($path)) {
            $this->error("Missing {$path}.");

            return self::FAILURE;
        }

        $entries = json_decode((string) file_get_contents($path), true);
        $entries = is_array($entries) ? $entries : [];

        $commit = (bool) $this->option('commit');

        $this->info(sprintf(
            '%s tenant="%s" (%d source entries loaded)',
            $commit ? 'COMMIT run' : 'DRY RUN (no writes — pass --commit to apply)',
            $tenant->slug,
            count($entries),
        ));

        $report = $importer->run($tenant, $entries, $commit);

        $counts = [];
        foreach ($report as $row) {
            $counts[$row['action']] = ($counts[$row['action']] ?? 0) + 1;
        }

        $this->table(
            ['url', 'label', 'action', 'reason'],
            array_map(fn (array $row) => [
                Str::limit((string) $row['url'], 60),
                Str::limit((string) $row['label'], 30),
                $row['action'],
                Str::limit((string) $row['reason'], 50),
            ], $report),
        );

        $this->newLine();
        $this->info('Summary: '.collect($counts)->map(fn ($n, $action) => "{$action}={$n}")->implode(', '));

        return self::SUCCESS;
    }
}
