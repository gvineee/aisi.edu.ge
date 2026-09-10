<?php

namespace App\Console\Commands;

use App\Domain\Tenancy\Models\BrandSetting;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Copies an approved logo asset into the tenant's public brand storage and
 * points its BrandSetting row at it. Editing a seeder's logo_path literal
 * does nothing for a tenant row that already exists (local dev, production)
 * — this command is the idempotent, re-runnable way to actually change it:
 * safe to run twice (Storage::put overwrites, and the DB update only ever
 * touches this one tenant's logo_path column).
 */
class SyncTenantLogo extends Command
{
    protected $signature = 'brand:sync-logo
        {--tenant=aisi : Tenant slug to update}
        {--source=resources/branding/aisi/logo.png : Path (relative to the app base path) to the logo file to install}';

    protected $description = 'Install an approved logo file into a tenant\'s public brand storage (idempotent)';

    public function handle(): int
    {
        $slug = (string) $this->option('tenant');
        $sourceRelative = (string) $this->option('source');
        $sourcePath = base_path($sourceRelative);

        $tenant = Tenant::query()->where('slug', $slug)->first();

        if (! $tenant) {
            $this->error("No tenant found with slug \"{$slug}\".");

            return self::FAILURE;
        }

        if (! is_file($sourcePath)) {
            $this->error("Source file not found: {$sourcePath}");

            return self::FAILURE;
        }

        $brand = BrandSetting::query()->where('tenant_id', $tenant->id)->first();

        if (! $brand) {
            $this->error("Tenant \"{$slug}\" has no brand_settings row yet — this command only updates an existing one.");

            return self::FAILURE;
        }

        $contents = file_get_contents($sourcePath);

        if ($contents === false) {
            $this->error("Could not read source file: {$sourcePath}");

            return self::FAILURE;
        }

        $storagePath = "tenants/{$slug}/logo.png";
        Storage::disk('public')->put($storagePath, $contents);

        $brand->logo_path = $storagePath;
        $brand->save();

        $this->info("Logo installed for tenant \"{$slug}\": {$storagePath}");

        return self::SUCCESS;
    }
}
