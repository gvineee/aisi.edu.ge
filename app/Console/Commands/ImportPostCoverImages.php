<?php

namespace App\Console\Commands;

use App\Domain\Content\Models\ContentImportRecord;
use App\Domain\Content\Models\Post;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Attaches real cover images to already-imported posts. ImportContentRecords
 * deliberately never wires cover_image_path (see its own doc-comment) —
 * this is that documented follow-up. Matches each post via
 * ContentImportRecord (source_type=posts, source_id) rather than a
 * hardcoded Post id, so the same mapping file works regardless of which
 * database (local dev vs production) it's run against.
 *
 * Directory must contain files named "post-<source_id>.<ext>" plus a
 * mapping.json ({sourceId, file}[]) — both produced by the one-off staging
 * script that resolved real image files from content-migration/'s already
 * -downloaded assets (never fabricated; every file is the school's own real
 * photo for that post, resolved via the WordPress media API's featured_media
 * id -> asset-manifest.json).
 */
class ImportPostCoverImages extends Command
{
    protected $signature = 'content:import-post-images {directory} {--tenant=aisi}';

    protected $description = 'Attach real cover images to already-imported posts, matched via ContentImportRecord';

    public function handle(): int
    {
        $directory = rtrim((string) $this->argument('directory'), '/\\');
        $tenantSlug = (string) $this->option('tenant');

        if (! is_dir($directory)) {
            $this->error("Directory not found: {$directory}");

            return self::FAILURE;
        }

        $mappingPath = "{$directory}/mapping.json";

        if (! is_file($mappingPath)) {
            $this->error("mapping.json not found in {$directory}");

            return self::FAILURE;
        }

        $tenant = Tenant::query()->where('slug', $tenantSlug)->first();

        if (! $tenant) {
            $this->error("No tenant found with slug \"{$tenantSlug}\".");

            return self::FAILURE;
        }

        /** @var array<int, array{sourceId: int, file: string}> $mapping */
        $mapping = json_decode((string) file_get_contents($mappingPath), true);

        $attached = 0;
        $skipped = 0;

        foreach ($mapping as $row) {
            $sourceId = (int) $row['sourceId'];
            $file = "{$directory}/{$row['file']}";

            if (! is_file($file)) {
                $this->warn("Missing file for source_id {$sourceId}: {$file}");
                $skipped++;

                continue;
            }

            $importRecord = ContentImportRecord::query()
                ->where('tenant_id', $tenant->id)
                ->where('source_type', 'posts')
                ->where('source_id', $sourceId)
                ->first();

            if (! $importRecord || ! $importRecord->importable_id) {
                $this->warn("No ContentImportRecord/importable post for source_id {$sourceId} — skipping.");
                $skipped++;

                continue;
            }

            $post = Post::query()
                ->where('tenant_id', $tenant->id)
                ->find($importRecord->importable_id);

            if (! $post) {
                $this->warn("ContentImportRecord points at post id {$importRecord->importable_id} but it no longer exists — skipping source_id {$sourceId}.");
                $skipped++;

                continue;
            }

            $extension = pathinfo($file, PATHINFO_EXTENSION);
            $storagePath = "media/{$tenant->id}/post-covers/{$sourceId}.{$extension}";
            Storage::disk('public')->put($storagePath, (string) file_get_contents($file));

            $post->cover_image_path = $storagePath;
            $post->save();

            $this->info("Cover image attached: \"{$post->title}\" (source_id {$sourceId})");
            $attached++;
        }

        $this->newLine();
        $this->info("{$attached} cover image(s) attached, {$skipped} skipped.");

        return self::SUCCESS;
    }
}
