<?php

namespace App\Console\Commands;

use App\Domain\Governance\AuditLogger;
use App\Domain\Integrations\OneRosterExporter;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * OneRoster 1.2-style CSV export for one tenant. `--dry-run` only reports
 * counts and validation issues. A real export is written to the private
 * disk (never public) and audited. Not a certified OneRoster
 * implementation.
 */
class ExportOneRoster extends Command
{
    protected $signature = 'oneroster:export {tenant : Tenant slug} {--dry-run} {--reason=}';

    protected $description = 'Export a tenant roster as OneRoster-style CSV files (private storage, audited)';

    public function handle(OneRosterExporter $exporter, AuditLogger $auditLogger): int
    {
        $tenant = Tenant::query()->where('slug', (string) $this->argument('tenant'))->first();

        if ($tenant === null) {
            $this->error('Tenant not found.');

            return self::FAILURE;
        }

        $result = $exporter->build($tenant);

        foreach ($result['counts'] as $file => $count) {
            $this->line("{$file}: {$count}");
        }

        foreach ($result['issues'] as $issue) {
            $this->warn($issue);
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run — nothing written.');

            return self::SUCCESS;
        }

        $directory = "exports/{$tenant->id}/oneroster-".Carbon::now()->format('Ymd-His');

        foreach ($result['files'] as $name => $rows) {
            $handle = fopen('php://temp', 'r+');

            if ($handle === false) {
                $this->error('Could not open a temporary stream.');

                return self::FAILURE;
            }

            foreach ($rows as $row) {
                fputcsv($handle, $row, ',', '"', '');
            }

            rewind($handle);
            Storage::disk('local')->put("{$directory}/{$name}", (string) stream_get_contents($handle));
            fclose($handle);
        }

        $auditLogger->record($tenant->id, 'export.oneroster', $tenant, null, [
            'directory' => $directory,
            'counts' => $result['counts'],
            'issues' => count($result['issues']),
            'reason' => $this->option('reason'),
        ]);

        $this->info("Written to private storage: {$directory}");

        return self::SUCCESS;
    }
}
