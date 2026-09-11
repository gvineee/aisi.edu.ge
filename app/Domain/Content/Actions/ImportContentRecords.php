<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\ContentImportRecord;
use App\Domain\Content\Models\Page;
use App\Domain\Content\Models\Post;
use App\Domain\Content\Support\LegacyMediaManifest;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Str;

/**
 * Idempotent WordPress -> CMS importer (docs/08-content-migration.md §6).
 *
 * Reads the pre-collected `content-migration/cms-import.json` records and,
 * per record, decides one of: create / update / skip_unchanged / conflict /
 * blocked / template_review / skipped_out_of_scope. Nothing is written to
 * the database unless $commit is true — the caller (console command)
 * defaults to dry-run.
 *
 * Every created Page/Post is always status=draft, regardless of the
 * source's own cms_status — this action is never authorized to publish.
 */
class ImportContentRecords
{
    /**
     * Source ids that are known duplicates of the same target page. They
     * are still imported as separate drafts (nothing is silently merged) —
     * a human reviewer picks the canonical one later.
     *
     * @var array<string, array<int, int>>
     */
    private const MERGE_GROUPS = [
        'about-history' => [2371, 1241, 2159],
        'about-why-aisi' => [2347, 871],
    ];

    /**
     * Source ids that must never be imported as a normal factual page.
     *
     * @var array<int, string>
     */
    private const BLOCKED_SOURCE_IDS = [
        54 => 'ძველი მთავარი გვერდის layout/footer ნარჩენია ("აკრედიტაცია"), არა ფაქტობრივი გვერდი — docs/08-content-migration.md §4-ის მიხედვით არასდროს არ შედის ჩვეულებრივ იმპორტში.',
    ];

    /**
     * The `documents` source type has no numeric source_id (scraped without
     * a WordPress post ID), so it can't use BLOCKED_SOURCE_IDS above. Manual
     * inspection of all 30 records found three distinct sub-groups by URL
     * pattern: /courses/* (11, WordPress theme demo "Related Courses"
     * catalog, Lorem Ipsum body), /event/* (7, all but one a theme demo
     * event — Lorem Ipsum body, non-Georgian placeholder city/date), and
     * /research/* (12, real per-grade curriculum archive pages linking out
     * to Drive/Scribd documents). Matched in order below; first hit wins.
     *
     * @var array<string, string>
     */
    private const DOCUMENT_URL_TEMPLATE_JUNK_PATTERNS = [
        '/courses/' => 'WordPress theme demo "Related Courses" catalog card (Lorem Ipsum body, no Aisi-specific content) — flagged for human review, not imported as a real page.',
        '/event/' => 'WordPress theme demo event listing (Lorem Ipsum body, non-Aisi placeholder city/date) — flagged for human review, not imported as a real page.',
    ];

    /**
     * One real record mixed into the otherwise-all-demo /event/* URL set:
     * a genuine Aisi/ISM University of Management and Economics (Warsaw)
     * webinar announcement, in Georgian, with a real 2021 date. Checked
     * BEFORE the generic /event/ junk pattern above so it isn't caught by it.
     *
     * @var array<int, string>
     */
    private const DOCUMENT_KEY_IMPORT_OVERRIDES = [
        'legacy-ca0b723d7c8b',
    ];

    private readonly LegacyMediaManifest $mediaManifest;

    public function __construct(?LegacyMediaManifest $mediaManifest = null)
    {
        $this->mediaManifest = $mediaManifest ?? new LegacyMediaManifest;
    }

    /**
     * @param  array<int, array<string, mixed>>  $records  Decoded cms-import.json "records" array.
     * @param  array<int, string>  $only  Source types to actually act on (others report as skipped_out_of_scope).
     * @param  array<int, string>  $failedUrls  source_url values already known to have failed collection (failures.json).
     * @return array<int, array<string, mixed>> One report row per input record.
     */
    public function run(Tenant $tenant, array $records, array $only, array $failedUrls, string $importBatchId, bool $commit): array
    {
        $failedUrlSet = array_flip($failedUrls);
        $report = [];

        foreach ($records as $record) {
            $report[] = $this->decideAndMaybeApply($tenant, $record, $only, $failedUrlSet, $importBatchId, $commit);
        }

        return $report;
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<int, string>  $only
     * @param  array<string, int>  $failedUrlSet
     * @return array<string, mixed>
     */
    private function decideAndMaybeApply(Tenant $tenant, array $record, array $only, array $failedUrlSet, string $importBatchId, bool $commit): array
    {
        $key = (string) $record['key'];
        $type = (string) $record['type'];
        $sourceId = $record['source_id'] !== null ? (int) $record['source_id'] : null;
        $sourceUrl = (string) $record['source_url'];
        $title = (string) $record['title'];
        $group = $this->mergeGroupFor($sourceId);

        $base = [
            'key' => $key,
            'type' => $type,
            'source_id' => $sourceId,
            'title' => $title,
            'target_path' => $record['target_path'] ?? null,
            'merge_group' => $group,
        ];

        if (! in_array($type, $only, true)) {
            return $base + ['action' => 'skipped_out_of_scope', 'reason' => "type '{$type}' is not in --only ({$this->joinTypes($only)})"];
        }

        // Look up any existing tracking row FIRST, before evaluating the
        // blocked/failed-url conditions below — otherwise a second run over
        // an already-blocked record tries to insert a duplicate tracking
        // row and hits the (tenant_id, source_system, source_key) unique
        // constraint instead of recognizing it was already decided.
        $existing = ContentImportRecord::query()
            ->where('tenant_id', $tenant->id)
            ->where('source_system', 'aisi-wordpress')
            ->where('source_key', $key)
            ->first();

        if ($existing !== null) {
            $sourceText = $this->sourceText($record);
            $sourceChecksum = hash('sha256', $sourceText);

            return $base + $this->reconcileExisting($tenant, $record, $existing, $sourceText, $sourceChecksum, $importBatchId, $commit);
        }

        if ($sourceId !== null && array_key_exists($sourceId, self::BLOCKED_SOURCE_IDS)) {
            $this->recordDecision($tenant, $record, ContentImportRecord::STATUS_BLOCKED, null, $importBatchId, $commit);

            return $base + ['action' => 'blocked', 'reason' => self::BLOCKED_SOURCE_IDS[$sourceId]];
        }

        if (isset($failedUrlSet[$sourceUrl])) {
            $this->recordDecision($tenant, $record, ContentImportRecord::STATUS_BLOCKED, null, $importBatchId, $commit);

            return $base + ['action' => 'blocked', 'reason' => 'source_url is listed in content-migration/failures.json — content not reliably collected'];
        }

        if ($type === 'documents') {
            $classification = $this->classifyDocumentRecord($key, $sourceUrl);

            if ($classification !== null) {
                $this->recordDecision($tenant, $record, ContentImportRecord::STATUS_TEMPLATE_REVIEW, null, $importBatchId, $commit);

                return $base + ['action' => 'template_review', 'reason' => $classification];
            }
        }

        $sourceText = $this->sourceText($record);
        $sourceChecksum = hash('sha256', $sourceText);
        $row = $base + $this->create($tenant, $record, $sourceText, $sourceChecksum, $group, $importBatchId, $commit);

        $media = $this->describeFeaturedMedia($record);
        if ($media !== null) {
            $row['media_hint'] = $media;
        }

        return $row;
    }

    /**
     * Real content decided per-record for `documents` (see the class-level
     * doc comments on the two constants above); returns a human reason
     * string if the record should be flagged template_review instead of
     * imported, or null if it should proceed to the normal create() path
     * (the /research/* pages, and the one real /event/* override).
     */
    private function classifyDocumentRecord(string $key, string $sourceUrl): ?string
    {
        if (in_array($key, self::DOCUMENT_KEY_IMPORT_OVERRIDES, true)) {
            return null;
        }

        foreach (self::DOCUMENT_URL_TEMPLATE_JUNK_PATTERNS as $pattern => $reason) {
            if (str_contains($sourceUrl, $pattern)) {
                return $reason;
            }
        }

        return null;
    }

    /**
     * Reports (never writes anywhere) a resolved featured-image hint for
     * `posts` records that carry a nonzero WordPress `featured_media` id.
     * Resolution: featured_media id -> source image URL, via the raw media
     * API dump (content-migration/raw/api/media-*.json, gitignored working
     * data, present locally) -> local downloaded file + rights status, via
     * content-migration/asset-manifest.json. Deliberately does NOT write a
     * cover_image_path or copy any bytes: every asset in the manifest is
     * still `rights_status: school_confirmation_required`, so actually
     * wiring/display a resolved image is a follow-up, not this importer's
     * call to make. `pages`/`documents`/`teachers` records never carry a
     * featured_media id in this dataset, so this only ever fires for posts.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>|null
     */
    private function describeFeaturedMedia(array $record): ?array
    {
        $mediaId = (int) ($record['featured_media'] ?? 0);

        if ($mediaId === 0) {
            return null;
        }

        $mediaUrl = $this->mediaManifest->mediaIdToUrl()[$mediaId] ?? null;

        if ($mediaUrl === null) {
            return ['featured_media_id' => $mediaId, 'resolved' => false, 'reason' => 'id not found in raw media API dump'];
        }

        $asset = $this->mediaManifest->assetManifestByUrl()[$mediaUrl] ?? null;

        if ($asset === null) {
            return ['featured_media_id' => $mediaId, 'source_url' => $mediaUrl, 'resolved' => false, 'reason' => 'not present in asset-manifest.json'];
        }

        return [
            'featured_media_id' => $mediaId,
            'source_url' => $mediaUrl,
            'local_path' => $asset['local_path'] ?? null,
            'rights_status' => $asset['rights_status'] ?? null,
            'resolved' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function create(Tenant $tenant, array $record, string $sourceText, string $sourceChecksum, ?string $group, string $importBatchId, bool $commit): array
    {
        $slug = 'import-'.Str::slug((string) $record['key']);
        $type = (string) $record['type'];
        // Only the `posts` source type becomes a Post; `pages`, the real
        // (non-junk) `documents` records, and `teachers` all become a plain
        // draft Page — there is no dedicated staff-profile model yet, so a
        // Page is the closest real thing a `teachers` record can become
        // without inventing a new feature under this ticket (a future
        // Teacher/Staff model should reclaim these later).
        $extraReason = $type === 'teachers'
            ? ' (imported as a generic Page — no dedicated staff-profile model exists yet)'
            : '';

        if (! $commit) {
            return [
                'action' => 'create',
                'reason' => ($group !== null
                    ? "new draft; flagged merge candidate group '{$group}' — not auto-merged"
                    : 'new draft').$extraReason,
                'slug' => $slug,
            ];
        }

        $importable = $type === 'posts'
            ? $this->createPost($tenant, $record, $sourceText, $slug)
            : $this->createPage($tenant, $record, $sourceText, $slug);

        $this->recordDecision($tenant, $record, ContentImportRecord::STATUS_IMPORTED, $importable, $importBatchId, true, $sourceChecksum, $sourceChecksum);

        return [
            'action' => 'created',
            'reason' => ($group !== null ? "flagged merge candidate group '{$group}' — not auto-merged" : 'created as draft').$extraReason,
            'slug' => $slug,
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function createPage(Tenant $tenant, array $record, string $sourceText, string $slug): Page
    {
        /** @var Page $page */
        $page = $tenant->pages()->create([
            'slug' => $slug,
            'locale' => 'ka',
            'title' => (string) $record['title'],
            'excerpt' => is_string($record['excerpt'] ?? null) && $record['excerpt'] !== '' ? mb_substr((string) $record['excerpt'], 0, 250) : null,
            'blocks' => [
                [
                    'type' => 'text',
                    'heading' => (string) $record['title'],
                    'body' => $sourceText,
                ],
            ],
            'status' => Page::STATUS_DRAFT,
            'seo_title' => null,
            'seo_description' => null,
        ]);

        return $page;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function createPost(Tenant $tenant, array $record, string $sourceText, string $slug): Post
    {
        /** @var Post $post */
        $post = $tenant->posts()->create([
            'slug' => $slug,
            'locale' => 'ka',
            'title' => (string) $record['title'],
            'excerpt' => is_string($record['excerpt'] ?? null) && $record['excerpt'] !== '' ? mb_substr((string) $record['excerpt'], 0, 250) : null,
            'body' => $sourceText,
            'status' => Post::STATUS_DRAFT,
            'published_at' => null,
        ]);

        return $post;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function reconcileExisting(Tenant $tenant, array $record, ContentImportRecord $existing, string $sourceText, string $sourceChecksum, string $importBatchId, bool $commit): array
    {
        if (in_array($existing->review_status, [ContentImportRecord::STATUS_BLOCKED, ContentImportRecord::STATUS_TEMPLATE_REVIEW], true)) {
            return ['action' => 'blocked', 'reason' => "already tracked as {$existing->review_status}"];
        }

        $importable = $existing->importable;

        if (! $importable instanceof Page && ! $importable instanceof Post) {
            return ['action' => 'conflict', 'reason' => 'tracking row exists but the imported Page/Post is missing (deleted?) — needs human review, not auto-recreated'];
        }

        $currentLocalText = $importable instanceof Page
            ? (string) ($importable->blocks[0]['body'] ?? '')
            : $importable->body;
        $currentLocalChecksum = hash('sha256', $currentLocalText);

        if ($currentLocalChecksum !== $existing->local_checksum) {
            return ['action' => 'conflict', 'reason' => 'local content changed since last import — left untouched, not overwritten'];
        }

        if ($sourceChecksum === $existing->source_checksum) {
            return ['action' => 'skip_unchanged', 'reason' => 'source and local both unchanged since last import'];
        }

        if (! $commit) {
            return ['action' => 'update', 'reason' => 'source content changed since last import; local untouched by a human — safe to refresh'];
        }

        if ($importable instanceof Page) {
            $blocks = $importable->blocks;
            $blocks[0]['body'] = $sourceText;
            $importable->blocks = $blocks;
            $importable->save();
        } else {
            $importable->body = $sourceText;
            $importable->save();
        }

        $existing->source_checksum = $sourceChecksum;
        $existing->local_checksum = $sourceChecksum;
        $existing->import_batch_id = $importBatchId;
        $existing->imported_at = now();
        $existing->save();

        return ['action' => 'updated', 'reason' => 'refreshed draft content from changed source'];
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function recordDecision(Tenant $tenant, array $record, string $status, Page|Post|null $importable, string $importBatchId, bool $commit, ?string $sourceChecksum = null, ?string $localChecksum = null): void
    {
        if (! $commit) {
            return;
        }

        $tenant->contentImportRecords()->create([
            'source_system' => 'aisi-wordpress',
            'source_key' => (string) $record['key'],
            'source_id' => $record['source_id'],
            'source_type' => (string) $record['type'],
            'source_url' => (string) $record['source_url'],
            'importable_type' => $importable !== null ? $importable::class : null,
            'importable_id' => $importable?->getKey(),
            'import_batch_id' => $importBatchId,
            'source_checksum' => $sourceChecksum,
            'local_checksum' => $localChecksum,
            'review_status' => $status,
            'imported_at' => $status === ContentImportRecord::STATUS_IMPORTED ? now() : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function sourceText(array $record): string
    {
        $text = $record['original_clean_text'] ?? $record['body'] ?? '';

        return trim(strip_tags((string) $text));
    }

    private function mergeGroupFor(?int $sourceId): ?string
    {
        if ($sourceId === null) {
            return null;
        }

        foreach (self::MERGE_GROUPS as $group => $ids) {
            if (in_array($sourceId, $ids, true)) {
                return $group;
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $only
     */
    private function joinTypes(array $only): string
    {
        return implode(',', $only);
    }
}
