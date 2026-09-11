<?php

namespace App\Domain\Content\Support;

use App\Domain\Content\Actions\ImportContentRecords;
use App\Domain\Content\Actions\SyncContentMedia;

/**
 * Shared read-only lookup over the two gitignored legacy-collection dumps
 * (docs/08-content-migration.md §6/§8):
 *
 * - `content-migration/raw/api/media-*.json` — the raw WordPress REST API
 *   `/wp-json/wp/v2/media` page dumps, mapping a WordPress `featured_media`
 *   id to its original `source_url`. Not committed to git (working data);
 *   present only where `scripts/collect-public-content.py` has actually run.
 * - `content-migration/asset-manifest.json` — committed, maps a source_url
 *   to the locally-cached copy's relative path/size/checksum/rights status.
 *
 * Used by both {@see ImportContentRecords}
 * (reports a featured-image hint) and
 * {@see SyncContentMedia} (actually copies the
 * bytes into tenant storage once a human has confirmed rights). Extracted
 * here so both share one lookup instead of two independent copies.
 */
class LegacyMediaManifest
{
    /** @var array<int, string>|null */
    private ?array $mediaIdToUrlCache = null;

    /** @var array<string, array<string, mixed>>|null */
    private ?array $assetManifestCache = null;

    public function __construct(
        private readonly string $mediaDumpGlob = '',
        private readonly string $manifestPath = '',
    ) {}

    private function mediaDumpGlob(): string
    {
        return $this->mediaDumpGlob !== '' ? $this->mediaDumpGlob : base_path('content-migration/raw/api/media-*.json');
    }

    private function manifestPath(): string
    {
        return $this->manifestPath !== '' ? $this->manifestPath : base_path('content-migration/asset-manifest.json');
    }

    /**
     * @return array<int, string> WordPress media id => original source_url
     */
    public function mediaIdToUrl(): array
    {
        if ($this->mediaIdToUrlCache !== null) {
            return $this->mediaIdToUrlCache;
        }

        $map = [];

        foreach (glob($this->mediaDumpGlob()) ?: [] as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);

            if (! is_array($decoded)) {
                continue;
            }

            foreach ($decoded as $entry) {
                if (is_array($entry) && isset($entry['id'], $entry['source_url']) && is_string($entry['source_url'])) {
                    $map[(int) $entry['id']] = $entry['source_url'];
                }
            }
        }

        return $this->mediaIdToUrlCache = $map;
    }

    /**
     * @return array<string, array<string, mixed>> source_url => manifest row
     */
    public function assetManifestByUrl(): array
    {
        if ($this->assetManifestCache !== null) {
            return $this->assetManifestCache;
        }

        $path = $this->manifestPath();
        $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $map = [];

        if (is_array($decoded)) {
            foreach ($decoded as $entry) {
                if (is_array($entry) && isset($entry['source_url']) && is_string($entry['source_url'])) {
                    $map[$entry['source_url']] = $entry;
                }
            }
        }

        return $this->assetManifestCache = $map;
    }

    /**
     * @return array<string, mixed>|null the asset-manifest row for a WordPress featured_media id, or null if unresolved at either hop
     */
    public function assetForMediaId(int $mediaId): ?array
    {
        $url = $this->mediaIdToUrl()[$mediaId] ?? null;

        if ($url === null) {
            return null;
        }

        return $this->assetManifestByUrl()[$url] ?? null;
    }
}
