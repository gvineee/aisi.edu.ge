<?php

namespace App\Console\Commands;

use App\Domain\Content\Models\LegacyRedirect;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Operator command for a single hand-reviewed old-site URL mapping (e.g. the
 * rows of content-migration/redirect-map.csv once someone has checked them).
 * Manual rows are never overwritten by content:sync-redirects.
 */
class SetLegacyRedirect extends Command
{
    protected $signature = 'content:redirect
        {tenant : Tenant slug}
        {from : Old URL or path}
        {to? : New site-relative path, e.g. /library}
        {--remove : Delete the redirect for {from}}';

    protected $description = 'Add, change or remove one manual legacy-URL 301 redirect for a tenant';

    public function handle(): int
    {
        $tenant = Tenant::query()->where('slug', (string) $this->argument('tenant'))->first();

        if ($tenant === null) {
            $this->error('Tenant not found.');

            return self::FAILURE;
        }

        $source = LegacyRedirect::normalizePath((string) $this->argument('from'));
        $existing = LegacyRedirect::query()
            ->where('tenant_id', $tenant->id)
            ->where('source_hash', LegacyRedirect::hashPath($source))
            ->first();

        if ($this->option('remove')) {
            $existing?->delete();
            $this->info($existing ? "Removed {$source}" : "No redirect for {$source}");

            return self::SUCCESS;
        }

        $target = (string) $this->argument('to');

        // Site-relative only: an absolute or protocol-relative target would
        // turn the school's domain into an open redirect.
        if (! str_starts_with($target, '/') || str_starts_with($target, '//') || $source === '/' || $source === $target) {
            $this->error('Target must be a site-relative path starting with a single "/", different from the source.');

            return self::FAILURE;
        }

        $redirect = $existing ?? new LegacyRedirect;
        $redirect->tenant_id = $tenant->id;
        $redirect->fill([
            'source_path' => $source,
            'source_hash' => LegacyRedirect::hashPath($source),
            'target_path' => $target,
            'status_code' => 301,
            'origin' => LegacyRedirect::ORIGIN_MANUAL,
        ])->save();

        $this->info("{$source} → {$target} (301)");

        return self::SUCCESS;
    }
}
