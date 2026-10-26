<?php

namespace App\Console\Commands;

use App\Domain\Content\Models\ContentMediaLink;
use App\Domain\Governance\AuditLogger;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Replaces images the content import could only get as a resized variant
 * (`resolution = variant_only`, e.g. IMG_3582-360x370.jpeg) with the
 * original from the old site's uploads folder (IMG_3582.jpeg), once that
 * folder is available. Preview by default; only images are accepted, and
 * only when the original's name is exactly the variant's without the size
 * suffix. Each replacement is audited.
 */
class UpgradeVariantMediaCommand extends Command
{
    protected $signature = 'content:upgrade-variant-media
        {uploads : The old site\'s wp-content/uploads folder}
        {--tenant= : Tenant slug (required)}
        {--commit : Replace (default is a preview)}';

    protected $description = 'Replace resized-only imported images with their originals from the old uploads folder (preview by default)';

    private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function handle(AuditLogger $auditLogger): int
    {
        $tenant = Tenant::query()->where('slug', (string) $this->option('tenant'))->first();
        $uploads = rtrim((string) $this->argument('uploads'), '/\\');

        if ($tenant === null || ! is_dir($uploads)) {
            $this->error('სკოლა (--tenant) ან uploads საქაღალდე ვერ მოიძებნა.');

            return self::FAILURE;
        }

        $commit = (bool) $this->option('commit');
        $rows = [];

        foreach (ContentMediaLink::query()->where('tenant_id', $tenant->id)->where('resolution', 'variant_only')->orderBy('id')->get() as $link) {
            $path = rawurldecode((string) parse_url($link->source_url, PHP_URL_PATH));

            if (preg_match('#/wp-content/uploads/(\d{4}/\d{2})/(.+?)-\d+x\d+(\.[A-Za-z0-9]+)$#u', $path, $m) !== 1) {
                $rows[] = [$link->id, basename($path), 'skipped', 'ზომის სუფიქსი ვერ ამოიცნო'];

                continue;
            }

            $original = "{$uploads}/{$m[1]}/{$m[2]}{$m[3]}";
            $bytes = is_file($original) ? (string) file_get_contents($original) : null;
            $mime = $bytes === null ? null : (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

            if ($bytes === null || ! in_array($mime, self::IMAGE_MIMES, true)) {
                $rows[] = [$link->id, basename($path), 'missing', $bytes === null ? 'ორიგინალი არ არის' : "არა სურათი ({$mime})"];

                continue;
            }

            $sha256 = hash('sha256', $bytes);
            $diskPath = 'content-migration/body/'.substr($sha256, 0, 16).'-'.basename($original);
            $rows[] = [$link->id, basename($path), $commit ? 'replaced' : 'would_replace', basename($original).' · '.round(strlen($bytes) / 1024).' KB'];

            if (! $commit) {
                continue;
            }

            if (! Storage::disk('public')->exists($diskPath)) {
                Storage::disk('public')->put($diskPath, $bytes);
            }

            $before = ['disk_path' => $link->disk_path, 'bytes' => $link->bytes];
            $link->update([
                'disk_path' => $diskPath,
                'mime' => $mime,
                'bytes' => strlen($bytes),
                'sha256' => $sha256,
                'source_url' => str_replace(basename($path), basename($original), $link->source_url),
                'resolution' => 'original',
            ]);
            $auditLogger->record($tenant->id, 'content.media_original_restored', $link, null, $before + ['new_bytes' => strlen($bytes)]);
        }

        $this->info($commit ? 'ჩანაცვლდა.' : 'Preview — არაფერი შეცვლილა.');
        $this->table(['link', 'ვარიანტი', 'შედეგი', 'დეტალი'], $rows);

        return self::SUCCESS;
    }
}
