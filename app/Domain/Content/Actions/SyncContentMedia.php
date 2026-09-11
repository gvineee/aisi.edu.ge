<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\ContentImportRecord;
use App\Domain\Content\Models\Post;
use App\Domain\Content\Support\LegacyMediaManifest;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Storage;

/**
 * Idempotent, tenant-scoped copy of already-imported Posts' resolved
 * featured-image asset into `storage/app/public/content-migration/...`, and
 * wiring of `posts.cover_image_path` to that new local path.
 *
 * Context (see AGENT-REPORT-content-migration.md and
 * docs/08-content-migration.md §6): {@see ImportContentRecords} already
 * resolves a `posts` record's WordPress `featured_media` id to an
 * asset-manifest.json row (source_url, local_path, sha256, rights_status)
 * and reports it as a `media_hint`, but deliberately never copies bytes or
 * writes `cover_image_path` — the source_url it resolves to only had a
 * *description* of a locally-cached file, not necessarily one that actually
 * existed on the machine running the importer. This action is the one that
 * actually reads the cached bytes, verifies them against the manifest's
 * recorded checksum, stores them, and only then writes the DB reference —
 * so a Post is never pointed at a path that doesn't hold real bytes.
 *
 * Every source asset in `content-migration/asset-manifest.json` still
 * carries `rights_status: school_confirmation_required` (docs/08 §6.9). This
 * action does not change that: the affected Post stays `status=draft`
 * regardless, exactly like every other importer path in this codebase — a
 * draft's own review queue is where a human confirms rights before publish,
 * not here.
 */
class SyncContentMedia
{
    public function __construct(
        private readonly LegacyMediaManifest $mediaManifest = new LegacyMediaManifest,
        private readonly string $assetsRoot = '',
        private readonly string $cmsImportPath = '',
        private readonly string $publicDiskPrefix = 'content-migration',
    ) {}

    private function assetsRoot(): string
    {
        return $this->assetsRoot !== '' ? $this->assetsRoot : base_path('content-migration');
    }

    private function cmsImportPath(): string
    {
        return $this->cmsImportPath !== '' ? $this->cmsImportPath : base_path('content-migration/cms-import.json');
    }

    /**
     * @return array<int, array<string, mixed>> one report row per already-imported `posts` ContentImportRecord that carries a featured_media id
     */
    public function run(Tenant $tenant, bool $commit, ?int $limit = null): array
    {
        $featuredMediaByKey = $this->featuredMediaByKey();

        $records = ContentImportRecord::query()
            ->where('tenant_id', $tenant->id)
            ->where('source_system', 'aisi-wordpress')
            ->where('source_type', 'posts')
            ->where('review_status', ContentImportRecord::STATUS_IMPORTED)
            ->where('importable_type', Post::class)
            ->orderBy('id')
            ->get();

        $report = [];

        foreach ($records as $record) {
            if ($limit !== null && count($report) >= $limit) {
                break;
            }

            $mediaId = $featuredMediaByKey[$record->source_key] ?? 0;

            if ($mediaId === 0) {
                continue;
            }

            $report[] = $this->syncOne($tenant, $record, $mediaId, $commit);
        }

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    private function syncOne(Tenant $tenant, ContentImportRecord $record, int $mediaId, bool $commit): array
    {
        $base = [
            'source_key' => $record->source_key,
            'importable_id' => $record->importable_id,
            'featured_media_id' => $mediaId,
        ];

        $asset = $this->mediaManifest->assetForMediaId($mediaId);

        if ($asset === null) {
            return $base + ['action' => 'unresolved', 'reason' => 'featured_media id not resolvable via raw media dump + asset-manifest.json'];
        }

        $localPath = (string) ($asset['local_path'] ?? '');
        $sourceFile = $localPath !== '' ? $this->assetsRoot().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $localPath) : '';

        if ($localPath === '' || ! is_file($sourceFile)) {
            return $base + [
                'action' => 'missing_source_file',
                'reason' => "manifest points at '{$localPath}', but no such file exists locally (content-migration/assets is gitignored working data — see docs/08-content-migration.md §2). Re-run scripts/collect-public-content.py, or restore that cache, before this can sync.",
            ];
        }

        $bytes = file_get_contents($sourceFile);

        if ($bytes === false) {
            return $base + ['action' => 'unreadable_source_file', 'reason' => "could not read {$sourceFile}"];
        }

        $expectedSha256 = (string) ($asset['sha256'] ?? '');
        $actualSha256 = hash('sha256', $bytes);

        if ($expectedSha256 !== '' && $actualSha256 !== $expectedSha256) {
            return $base + [
                'action' => 'checksum_mismatch',
                'reason' => "local file no longer matches asset-manifest.json's recorded sha256 (expected {$expectedSha256}, got {$actualSha256}) — not synced; re-collect before trusting this asset",
            ];
        }

        $diskPath = $this->publicDiskPrefix.'/'.basename($localPath);
        $rightsStatus = (string) ($asset['rights_status'] ?? 'school_confirmation_required');

        $alreadyOnDisk = Storage::disk('public')->exists($diskPath)
            && hash('sha256', (string) Storage::disk('public')->get($diskPath)) === $actualSha256;

        /** @var Post|null $post */
        $post = $record->importable_id !== null ? Post::query()->where('tenant_id', $tenant->id)->find($record->importable_id) : null;

        if ($post === null) {
            return $base + ['action' => 'post_missing', 'reason' => 'ContentImportRecord.importable_id does not resolve to a Post for this tenant — needs human review, not auto-recreated'];
        }

        if ($post->cover_image_path !== null && $post->cover_image_path !== $diskPath) {
            return $base + [
                'action' => 'conflict',
                'reason' => "Post.cover_image_path is already set to '{$post->cover_image_path}' (a human/other process set this) — not overwritten",
            ];
        }

        if ($alreadyOnDisk && $post->cover_image_path === $diskPath) {
            return $base + ['action' => 'skip_unchanged', 'reason' => 'already synced', 'disk_path' => $diskPath, 'rights_status' => $rightsStatus];
        }

        if (! $commit) {
            return $base + [
                'action' => $alreadyOnDisk ? 'link' : 'copy',
                'reason' => "would copy to storage/app/public/{$diskPath} and set Post.cover_image_path (rights_status={$rightsStatus}, still draft-only)",
                'disk_path' => $diskPath,
            ];
        }

        if (! $alreadyOnDisk) {
            Storage::disk('public')->put($diskPath, $bytes);
        }

        $post->cover_image_path = $diskPath;
        $post->save();

        return $base + [
            'action' => $alreadyOnDisk ? 'linked' : 'copied',
            'reason' => "storage/app/public/{$diskPath} (rights_status={$rightsStatus}, still draft-only)",
            'disk_path' => $diskPath,
            'rights_status' => $rightsStatus,
        ];
    }

    /**
     * @return array<string, int> cms-import.json record `key` => `featured_media` id (0 entries omitted)
     */
    private function featuredMediaByKey(): array
    {
        $path = $this->cmsImportPath();
        $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $records = is_array($decoded) && is_array($decoded['records'] ?? null) ? $decoded['records'] : [];

        $map = [];

        foreach ($records as $record) {
            if (! is_array($record) || ! isset($record['key'])) {
                continue;
            }

            $mediaId = (int) ($record['featured_media'] ?? 0);

            if ($mediaId !== 0) {
                $map[(string) $record['key']] = $mediaId;
            }
        }

        return $map;
    }
}
