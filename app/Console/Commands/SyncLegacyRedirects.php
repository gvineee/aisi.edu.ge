<?php

namespace App\Console\Commands;

use App\Domain\Content\Models\ContentImportRecord;
use App\Domain\Content\Models\LegacyRedirect;
use App\Domain\Content\Models\Page;
use App\Domain\Content\Models\Post;
use App\Domain\Content\Models\Teacher;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * Builds 301s from the content importer's own bookkeeping: every legacy
 * WordPress URL whose imported page/post/teacher is now published points at
 * that record's real public URL. Unpublished or skipped records get nothing,
 * so they keep returning 404 instead of sending visitors to a draft.
 * Dry-run by default, idempotent, and never overwrites a manual redirect.
 */
class SyncLegacyRedirects extends Command
{
    protected $signature = 'content:sync-redirects
        {tenant : Tenant slug}
        {--commit : Write the redirects (default is a dry run)}';

    protected $description = 'Create 301 redirects from legacy WordPress URLs to their published imports';

    public function handle(): int
    {
        $tenant = Tenant::query()->where('slug', (string) $this->argument('tenant'))->first();

        if ($tenant === null) {
            $this->error('Tenant not found.');

            return self::FAILURE;
        }

        $commit = (bool) $this->option('commit');
        $rows = [];
        $counts = ['create' => 0, 'update' => 0, 'unchanged' => 0, 'manual_kept' => 0, 'not_published' => 0];

        $records = ContentImportRecord::query()
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('importable_type')
            ->with('importable')
            ->orderBy('id')
            ->get();

        foreach ($records as $record) {
            $target = $this->publicPath($record->importable, $tenant->id);

            if ($target === null) {
                $counts['not_published']++;

                continue;
            }

            $source = LegacyRedirect::normalizePath($record->source_url);

            if ($source === $target || $source === '/') {
                continue;
            }

            $existing = LegacyRedirect::query()
                ->where('tenant_id', $tenant->id)
                ->where('source_hash', LegacyRedirect::hashPath($source))
                ->first();

            $action = match (true) {
                $existing === null => 'create',
                $existing->origin === LegacyRedirect::ORIGIN_MANUAL => 'manual_kept',
                $existing->target_path === $target => 'unchanged',
                default => 'update',
            };
            $counts[$action]++;
            $rows[] = [$action, $source, $target];

            if (! $commit || ! in_array($action, ['create', 'update'], true)) {
                continue;
            }

            $redirect = $existing ?? new LegacyRedirect;
            $redirect->tenant_id = $tenant->id;
            $redirect->fill([
                'source_path' => $source,
                'source_hash' => LegacyRedirect::hashPath($source),
                'target_path' => $target,
                'status_code' => 301,
                'origin' => LegacyRedirect::ORIGIN_IMPORT,
            ])->save();
        }

        $this->table(['action', 'legacy path', 'target'], $rows);
        $this->info(($commit ? 'Committed' : 'Dry run').': '.json_encode($counts));

        return self::SUCCESS;
    }

    private function publicPath(?Model $importable, int $tenantId): ?string
    {
        if ($importable === null || (int) $importable->getAttribute('tenant_id') !== $tenantId) {
            return null;
        }

        return match (true) {
            $importable instanceof Page && $importable->isPublished() => $importable->slug === 'home' ? '/' : '/'.$importable->slug,
            $importable instanceof Post && $importable->isPublished() => '/news/'.$importable->slug,
            $importable instanceof Teacher && $importable->isPublished() => '/teachers/'.$importable->slug,
            default => null,
        };
    }
}
