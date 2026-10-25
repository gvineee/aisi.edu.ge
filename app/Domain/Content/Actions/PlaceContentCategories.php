<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\ContentCategorizable;
use App\Domain\Content\Models\ContentCategory;
use App\Domain\Content\Models\ContentImportRecord;
use App\Domain\Content\Models\Page;
use App\Domain\Content\Models\Post;
use App\Domain\Content\Models\Teacher;
use App\Domain\Governance\AuditLogger;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Applies `content-migration/category-placement-plan.json` to a tenant:
 * puts each planned record's imported page/post into its category and
 * section. Dry-run by default (the whole run is rolled back).
 *
 * It only writes placements. Text, dates, media, status and slugs of the
 * content are never touched, nothing is published or unpublished, and a
 * placement a person made in the CMS is never overwritten. Every plan
 * record gets exactly one disposition:
 *
 * - placed / moved / unchanged — the placement was created, moved from an
 *   earlier plan placement, or already right;
 * - archived — an archive-review record, placed in the internal archive
 *   (or, with no imported target, left tracked in the import records);
 * - conflict — not applied: a manual placement differs, or a published
 *   item is proposed for the archive (it is not unpublished);
 * - pending — no imported target yet (blocked / not imported);
 * - error — the record is unknown to the import bookkeeping.
 *
 * Alongside, each row reports parity flags for review: whether the content
 * was edited after import, whether its date differs from the source date,
 * and, for team pages, whether a public teacher profile already carries
 * that person (evidence only — never merged).
 */
class PlaceContentCategories
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array<string, mixed>  $plan
     * @return array{counts: array<string, int>, rows: list<array<string, mixed>>}
     */
    public function handle(Tenant $tenant, array $plan, bool $commit, ?int $actorId = null): array
    {
        $records = $plan['records'] ?? null;

        if (! is_array($records) || ($plan['schema_version'] ?? null) !== 1) {
            throw new InvalidArgumentException('უცნობი გეგმის ფორმატი (schema_version 1 მოსალოდნელია).');
        }

        $rows = [];

        DB::beginTransaction();

        try {
            ContentCategory::ensureDefaults($tenant->id, $tenant->locale);
            $categories = ContentCategory::query()->where('tenant_id', $tenant->id)->where('locale', $tenant->locale)->get()->keyBy('slug');
            $positions = [];

            foreach ($records as $record) {
                $rows[] = $this->placeOne($tenant, $record, $categories->all(), $positions);
            }

            $commit ? DB::commit() : DB::rollBack();
        } catch (\Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        $counts = collect($rows)->countBy('disposition')->all();

        if ($commit) {
            $this->auditLogger->record($tenant->id, 'content.categories_placed', $tenant, $actorId, ['counts' => $counts]);
        }

        return ['counts' => $counts, 'rows' => $rows];
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, ContentCategory>  $categories
     * @param  array<string, int>  $positions
     * @return array<string, mixed>
     */
    private function placeOne(Tenant $tenant, array $record, array $categories, array &$positions): array
    {
        $sourceKey = (string) ($record['source_key'] ?? '');
        $categorySlug = (string) ($record['proposed_category'] ?? '');
        $section = isset($record['proposed_section']) ? (string) $record['proposed_section'] : null;

        $row = [
            'source_key' => $sourceKey,
            'source_url' => $record['source_url'] ?? null,
            'original_title' => $record['original_title'] ?? null,
            'original_published_at' => $record['original_published_at'] ?? null,
            'category' => $categorySlug,
            'section' => $section,
            'target' => null,
            'target_status' => null,
            'target_url' => null,
            'disposition' => null,
            'reason' => null,
            'flags' => [],
        ];

        $category = $categories[$categorySlug] ?? null;

        if ($category === null) {
            return ['disposition' => 'error', 'reason' => 'გეგმაში უცნობი კატეგორია'] + $row;
        }

        $import = ContentImportRecord::query()->where('tenant_id', $tenant->id)->where('source_key', $sourceKey)->first();

        if ($import === null) {
            return ['disposition' => 'error', 'reason' => 'ჩანაწერი იმპორტის აღრიცხვაში არ არის'] + $row;
        }

        $target = $import->importable;

        if (! $target instanceof Page && ! $target instanceof Post) {
            return $categorySlug === 'archive-review'
                ? ['disposition' => 'archived', 'reason' => "იმპორტში დარჩა „{$import->review_status}“ სტატუსით, საჯარო სამიზნე არ აქვს"] + $row
                : ['disposition' => 'pending', 'reason' => "სამიზნე ჯერ არ არის (import: {$import->review_status})"] + $row;
        }

        $row['target'] = class_basename($target).'#'.$target->id;
        $row['target_status'] = $target->status;
        $row['target_url'] = $target instanceof Post ? "/news/{$target->slug}" : "/{$target->slug}";
        $row['flags'] = $this->flags($tenant, $import, $target, $record, $categorySlug);

        if ($categorySlug === 'archive-review' && $target->status === Page::STATUS_PUBLISHED) {
            return ['disposition' => 'conflict', 'reason' => 'გამოქვეყნებულია, მაგრამ გეგმით არქივისთვისაა — არ გაუქმდა, საჭიროა სარედაქციო გადაწყვეტილება'] + $row;
        }

        $existing = ContentCategorizable::query()
            ->where('categorizable_type', $target::class)
            ->where('categorizable_id', $target->id)
            ->first();

        $planSource = 'plan:'.$sourceKey;

        if ($existing !== null && $existing->content_category_id === $category->id && $existing->section === $section) {
            return ['disposition' => $categorySlug === 'archive-review' ? 'archived' : 'unchanged'] + $row;
        }

        if ($existing !== null && $existing->placed_by_source !== $planSource) {
            return ['disposition' => 'conflict', 'reason' => 'CMS-ში ხელით სხვა კატეგორიაშია — ხელით განთავსება შენარჩუნდა'] + $row;
        }

        $positions[$category->slug] = ($positions[$category->slug] ?? 0) + 1;
        $placement = $existing ?? new ContentCategorizable(['categorizable_type' => $target::class, 'categorizable_id' => $target->id]);
        $placement->tenant_id = $tenant->id;
        $placement->fill([
            'content_category_id' => $category->id,
            'section' => $section,
            'position' => $positions[$category->slug],
            'placed_by_source' => $planSource,
        ])->save();

        return ['disposition' => match (true) {
            $categorySlug === 'archive-review' => 'archived',
            $existing !== null => 'moved',
            default => 'placed',
        }] + $row;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return list<string>
     */
    private function flags(Tenant $tenant, ContentImportRecord $import, Page|Post $target, array $record, string $categorySlug): array
    {
        $flags = [];

        // Same rule ImportContentRecords uses to protect local edits.
        $localText = $target instanceof Page ? (string) ($target->blocks[0]['body'] ?? '') : $target->body;

        if ($import->local_checksum !== null && hash('sha256', $localText) !== $import->local_checksum) {
            $flags[] = 'edited_after_import';
        }

        $original = $record['original_published_at'] ?? null;

        if (is_string($original) && $original !== '') {
            // The old site ran on UTC: its raw API has date === date_gmt.
            $source = Carbon::parse($original, 'UTC');

            if ($target->published_at === null) {
                $flags[] = $target->status === Page::STATUS_PUBLISHED ? 'published_without_date' : 'no_date_yet';
            } elseif (abs($target->published_at->diffInHours($source)) > 24) {
                $flags[] = 'date_differs_from_source';
            }
        }

        if (($record['original_title'] ?? null) !== null && trim((string) $record['original_title']) !== trim($target->title)) {
            $flags[] = 'title_edited';
        }

        if ($categorySlug === 'team') {
            $teacher = Teacher::query()->where('tenant_id', $tenant->id)->where('name', trim($target->title))->first();

            if ($teacher !== null) {
                $flags[] = "public_teacher_profile:{$teacher->slug}";
            }
        }

        return $flags;
    }
}
