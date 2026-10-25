<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\ContentImportRecord;
use App\Domain\Content\Models\ContentMediaLink;
use App\Domain\Content\Models\Page;
use App\Domain\Content\Models\Post;
use App\Domain\Content\Support\LegacyMediaManifest;
use App\Domain\Tenancy\Models\Tenant;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Carries the images and file attachments of imported WordPress pages and
 * posts into the new site — the part the plain-text body import dropped.
 * Preview by default.
 *
 * For each imported page/post it reads the raw API HTML (authoritative,
 * per CLAUDE-LEGACY-CONTENT-IMPORT.md) in document order and resolves:
 * - every content `<img>` to its **original** file through the WordPress
 *   media id (`data-id` / `wp-image-N`); only when the original was never
 *   collected does it fall back to the resized variant, flagged
 *   `variant_only`. Gallery membership, order, alt and caption are kept;
 * - every link to a PDF/Word/Excel file as an attachment with its label.
 *
 * Each file must exist in the local collection with the manifest checksum
 * and a MIME type that matches its role — an HTML error page saved as
 * `.jpg` is rejected. Facebook emoji images are skipped (reported), empty
 * `<img/>` tags and unknown files are reported as unresolved. The body
 * text is never touched, and a target that already has a different set of
 * media links is reported as a conflict, never rewritten.
 */
class SyncContentBodyMedia
{
    private const DOCUMENT_MIMES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/zip', // .docx/.xlsx are zip containers to finfo on some systems
    ];

    /** @var array<string, array<int, array<string, mixed>>>|null */
    private ?array $rawCache = null;

    /** @var array<string, array<string, mixed>>|null decoded source URL => manifest row */
    private ?array $assetsByDecodedUrl = null;

    public function __construct(
        private readonly LegacyMediaManifest $manifest = new LegacyMediaManifest,
        private readonly string $migrationRoot = '',
    ) {}

    /**
     * @return list<array<string, mixed>> one row per imported page/post with raw source HTML
     */
    public function run(Tenant $tenant, bool $commit): array
    {
        $rows = [];

        $records = ContentImportRecord::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('source_type', ['pages', 'posts'])
            ->whereNotNull('importable_id')
            ->orderBy('id')
            ->get();

        foreach ($records as $record) {
            $target = $record->importable;

            if (! $target instanceof Page && ! $target instanceof Post) {
                continue;
            }

            $html = $this->rawHtml($record->source_type, (int) $record->source_id);

            if ($html === null) {
                $rows[] = ['source_key' => $record->source_key, 'disposition' => 'no_raw_source', 'images' => 0, 'attachments' => 0, 'issues' => []];

                continue;
            }

            $rows[] = $this->syncOne($tenant, $record, $target, $html, $commit);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function syncOne(Tenant $tenant, ContentImportRecord $record, Page|Post $target, string $html, bool $commit): array
    {
        ['items' => $items, 'issues' => $issues, 'emoji' => $emoji] = $this->extract($html);

        $row = [
            'source_key' => $record->source_key,
            'target' => class_basename($target).'#'.$target->id,
            'target_status' => $target->status,
            'images' => count(array_filter($items, fn ($item) => $item['role'] === ContentMediaLink::ROLE_IMAGE)),
            'galleries' => count(array_unique(array_filter(array_column($items, 'group')))),
            'attachments' => count(array_filter($items, fn ($item) => $item['role'] === ContentMediaLink::ROLE_ATTACHMENT)),
            'variant_only' => count(array_filter($items, fn ($item) => $item['resolution'] === 'variant_only')),
            'emoji_skipped' => $emoji,
            'issues' => $issues,
            'disposition' => null,
        ];

        $existing = ContentMediaLink::query()
            ->where('tenant_id', $tenant->id)
            ->where('linkable_type', $target::class)
            ->where('linkable_id', $target->id)
            ->orderBy('role')
            ->orderBy('position')
            ->get();

        $wanted = collect($items)->sortBy([['role', 'asc'], ['position', 'asc']])->map(fn ($item) => $item['role'].'|'.$item['sha256'])->values()->all();
        $have = $existing->map(fn (ContentMediaLink $link) => $link->role.'|'.$link->sha256)->values()->all();

        if ($items === []) {
            return ['disposition' => $issues === [] ? 'nothing_to_sync' : 'unresolved'] + $row;
        }

        if ($have === $wanted) {
            return ['disposition' => 'unchanged'] + $row;
        }

        if ($existing->isNotEmpty()) {
            return ['disposition' => 'conflict', 'reason' => 'მედიის სხვა ნაკრები უკვე მიბმულია — არ გადაიწერა'] + $row;
        }

        if (! $commit) {
            return ['disposition' => 'would_link'] + $row;
        }

        DB::transaction(function () use ($tenant, $target, $items) {
            foreach ($items as $item) {
                if (! Storage::disk('public')->exists($item['disk_path'])) {
                    Storage::disk('public')->put($item['disk_path'], $item['bytes_content']);
                }

                $link = new ContentMediaLink(array_diff_key($item, ['bytes_content' => true, 'group' => true]) + [
                    'linkable_type' => $target::class,
                    'linkable_id' => $target->id,
                    'group_key' => $item['group'],
                ]);
                $link->tenant_id = $tenant->id;
                $link->save();
            }
        });

        return ['disposition' => 'linked'] + $row;
    }

    /**
     * @return array{items: list<array<string, mixed>>, issues: list<string>, emoji: int}
     */
    private function extract(string $html): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><div id="root">'.$html.'</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($document);

        $items = [];
        $issues = [];
        $emoji = 0;
        $positions = [ContentMediaLink::ROLE_IMAGE => 0, ContentMediaLink::ROLE_ATTACHMENT => 0];
        $galleries = [];
        $seenAttachments = [];

        foreach ($xpath->query('//img | //a[@href]') ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            if ($node->nodeName === 'img') {
                $src = $node->getAttribute('src');

                if (str_contains($src, 'emoji.php') || str_contains($src, '/emoji/')) {
                    $emoji++;

                    continue;
                }

                if ($src === '') {
                    $issues[] = 'ცარიელი <img/> წყაროში (სურათი API-ში არ დაბრუნდა)';

                    continue;
                }

                $mediaId = (int) ($node->getAttribute('data-id') ?: (preg_match('/wp-image-(\d+)/', $node->getAttribute('class'), $m) === 1 ? $m[1] : 0));
                $resolved = $this->resolveImage($src, $mediaId);

                if ($resolved === null) {
                    $issues[] = 'სურათი ლოკალურ კოლექციაში ვერ მოიძებნა: '.$this->shortUrl($src);

                    continue;
                }

                $file = $this->verifiedFile($resolved['asset'], 'image/');

                if (is_string($file)) {
                    $issues[] = $file.': '.$this->shortUrl($src);

                    continue;
                }

                $gallery = $this->galleryOf($node, $galleries);
                $items[] = $file + [
                    'role' => ContentMediaLink::ROLE_IMAGE,
                    'group' => $gallery,
                    'position' => ++$positions[ContentMediaLink::ROLE_IMAGE],
                    'source_url' => $resolved['url'],
                    'source_media_id' => $mediaId ?: null,
                    'resolution' => $resolved['resolution'],
                    'alt' => $node->getAttribute('alt') !== '' ? mb_substr($node->getAttribute('alt'), 0, 500) : null,
                    'caption' => $this->captionOf($node),
                    'label' => null,
                ];

                continue;
            }

            $href = $node->getAttribute('href');

            if (preg_match('/\.(pdf|docx?|xlsx?)(\?.*)?$/i', rawurldecode($href)) !== 1) {
                continue;
            }

            $key = rawurldecode($href);
            $label = trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? '');

            // The same file is often linked twice (title + "Download"): one attachment, the better label.
            if (isset($seenAttachments[$key])) {
                $index = $seenAttachments[$key];

                if (in_array(mb_strtolower((string) $items[$index]['label']), ['', 'download', 'ჩამოტვირთვა'], true) && $label !== '') {
                    $items[$index]['label'] = mb_substr($label, 0, 500);
                }

                continue;
            }

            $asset = $this->asset($href);

            if ($asset === null) {
                $issues[] = 'დანართი ლოკალურ კოლექციაში ვერ მოიძებნა: '.$this->shortUrl($href);

                continue;
            }

            $file = $this->verifiedFile($asset, null);

            if (is_string($file)) {
                $issues[] = $file.': '.$this->shortUrl($href);

                continue;
            }

            $seenAttachments[$key] = count($items);
            $items[] = $file + [
                'role' => ContentMediaLink::ROLE_ATTACHMENT,
                'group' => null,
                'position' => ++$positions[ContentMediaLink::ROLE_ATTACHMENT],
                'source_url' => $key,
                'source_media_id' => null,
                'resolution' => 'original',
                'alt' => null,
                'caption' => null,
                'label' => $label !== '' ? mb_substr($label, 0, 500) : null,
            ];
        }

        return ['items' => $items, 'issues' => array_values(array_unique($issues)), 'emoji' => $emoji];
    }

    /**
     * @return array{asset: array<string, mixed>, url: string, resolution: string}|null
     */
    private function resolveImage(string $src, int $mediaId): ?array
    {
        if ($mediaId !== 0) {
            $original = $this->manifest->mediaIdToUrl()[$mediaId] ?? null;

            if ($original !== null && ($asset = $this->asset($original)) !== null) {
                return ['asset' => $asset, 'url' => rawurldecode($original), 'resolution' => 'original'];
            }
        }

        $stripped = preg_replace('/-\d+x\d+(\.\w+)$/', '$1', $src) ?? $src;

        if ($stripped !== $src && ($asset = $this->asset($stripped)) !== null) {
            return ['asset' => $asset, 'url' => rawurldecode($stripped), 'resolution' => 'original'];
        }

        if (($asset = $this->asset($src)) !== null) {
            return ['asset' => $asset, 'url' => rawurldecode($src), 'resolution' => $stripped !== $src ? 'variant_only' : 'original'];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $asset
     * @return array{disk_path: string, mime: string, bytes: int, sha256: string, bytes_content: string}|string the file, or why it was rejected
     */
    private function verifiedFile(array $asset, ?string $mimePrefix): array|string
    {
        $localPath = (string) ($asset['local_path'] ?? '');
        $file = $this->root().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $localPath);
        $bytes = $localPath !== '' && is_file($file) ? file_get_contents($file) : false;

        if ($bytes === false) {
            return 'ფაილი ლოკალურად არ არის';
        }

        $sha256 = hash('sha256', $bytes);

        if (($asset['sha256'] ?? '') !== '' && $sha256 !== $asset['sha256']) {
            return 'checksum არ ემთხვევა manifest-ს';
        }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $allowed = $mimePrefix !== null ? str_starts_with($mime, $mimePrefix) : in_array($mime, self::DOCUMENT_MIMES, true);

        if (! $allowed) {
            return "მოულოდნელი ტიპი ({$mime})";
        }

        return [
            'disk_path' => 'content-migration/body/'.substr($sha256, 0, 16).'-'.basename($localPath),
            'mime' => $mime,
            'bytes' => strlen($bytes),
            'sha256' => $sha256,
            'bytes_content' => $bytes,
        ];
    }

    /**
     * @param  array<int, string>  $galleries  DOM node id => gallery key
     */
    private function galleryOf(DOMElement $image, array &$galleries): ?string
    {
        for ($node = $image->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
            $class = ' '.$node->getAttribute('class').' ';

            if (str_contains($class, ' wp-block-gallery ') || str_contains($class, ' gallery ') || str_contains($class, 'elementor-image-carousel')) {
                $id = spl_object_id($node);

                return $galleries[$id] ??= 'gallery-'.(count($galleries) + 1);
            }
        }

        return null;
    }

    private function captionOf(DOMElement $image): ?string
    {
        $figure = $image->parentNode;

        while ($figure instanceof DOMElement && $figure->nodeName !== 'figure') {
            $figure = $figure->parentNode;
        }

        if (! $figure instanceof DOMElement) {
            return null;
        }

        foreach ($figure->childNodes as $child) {
            if ($child instanceof DOMElement && $child->nodeName === 'figcaption') {
                $text = trim(preg_replace('/\s+/u', ' ', $child->textContent) ?? '');

                return $text !== '' ? $text : null;
            }
        }

        return null;
    }

    private function rawHtml(string $type, int $sourceId): ?string
    {
        if ($this->rawCache === null) {
            $this->rawCache = [];

            foreach (['pages', 'posts'] as $kind) {
                foreach (glob($this->root().'/raw/api/'.$kind.'-*.json') ?: [] as $file) {
                    foreach ((array) json_decode((string) file_get_contents($file), true) as $entry) {
                        if (is_array($entry) && isset($entry['id'])) {
                            $this->rawCache[$kind][(int) $entry['id']] = $entry;
                        }
                    }
                }
            }
        }

        $html = $this->rawCache[$type][$sourceId]['content']['rendered'] ?? null;

        return is_string($html) ? $html : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function asset(string $url): ?array
    {
        if ($this->assetsByDecodedUrl === null) {
            $this->assetsByDecodedUrl = [];

            foreach ($this->manifest->assetManifestByUrl() as $sourceUrl => $row) {
                $this->assetsByDecodedUrl[rawurldecode($sourceUrl)] = $row;
            }
        }

        return $this->assetsByDecodedUrl[rawurldecode($url)] ?? null;
    }

    private function root(): string
    {
        return $this->migrationRoot !== '' ? $this->migrationRoot : base_path('content-migration');
    }

    private function shortUrl(string $url): string
    {
        return mb_substr(rawurldecode($url), -80);
    }
}
