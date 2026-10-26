<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\ContentImportRecord;
use App\Domain\Content\Models\Page;
use App\Domain\Content\Models\Post;
use App\Domain\Governance\AuditLogger;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Imports the old site's English/Russian translations of school pages
 * (`content-migration/legacy-translations.json`, extracted by
 * scripts/extract-legacy-translations.py) as **drafts** next to the
 * Georgian original: same slug and type, the translation's own locale.
 * Preview by default.
 *
 * Nothing is published — the public site serves only the tenant's locale
 * until a multilingual site is switched on. Each translation is tracked as
 * a ContentImportRecord (`{original}@{locale}`), so a rerun is `unchanged`,
 * and a draft someone edited afterwards is a `conflict`, never overwritten.
 */
class ImportLegacyTranslations
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array<string, mixed>  $file
     * @return list<array<string, mixed>>
     */
    public function handle(Tenant $tenant, array $file, bool $commit, ?int $actorId = null): array
    {
        if (($file['schema_version'] ?? null) !== 1 || ! is_array($file['records'] ?? null)) {
            throw new InvalidArgumentException('უცნობი ფაილის ფორმატი (schema_version 1 მოსალოდნელია).');
        }

        $batchId = (string) Str::uuid();
        $rows = [];

        DB::beginTransaction();

        try {
            foreach ($file['records'] as $record) {
                $rows[] = $this->importOne($tenant, $record, $batchId);
            }

            $commit ? DB::commit() : DB::rollBack();
        } catch (\Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        if ($commit) {
            $this->auditLogger->record($tenant->id, 'content.translations_imported', $tenant, $actorId, [
                'counts' => collect($rows)->countBy('disposition')->all(),
            ]);
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function importOne(Tenant $tenant, array $record, string $batchId): array
    {
        $originalKey = (string) ($record['translation_of'] ?? '');
        $locale = (string) ($record['locale'] ?? '');
        $title = trim((string) ($record['title'] ?? ''));
        $paragraphs = array_values(array_filter(array_map('strval', (array) ($record['paragraphs'] ?? [])), fn (string $p) => trim($p) !== ''));
        $key = "{$originalKey}@{$locale}";
        $row = ['source_key' => $key, 'locale' => $locale, 'title' => $title, 'title_needs_review' => (bool) ($record['title_needs_review'] ?? false), 'target' => null, 'disposition' => null, 'reason' => null];

        if (! in_array($locale, ['en', 'ru'], true) || $paragraphs === [] || $title === '') {
            return ['disposition' => 'error', 'reason' => 'ლოკალი, სათაური ან ტექსტი აკლია'] + $row;
        }

        $original = ContentImportRecord::query()->where('tenant_id', $tenant->id)->where('source_key', $originalKey)->first()?->importable;

        if (! $original instanceof Page && ! $original instanceof Post) {
            return ['disposition' => 'pending', 'reason' => 'ქართული ორიგინალი ჯერ იმპორტირებული არ არის'] + $row;
        }

        $sourceChecksum = hash('sha256', (string) json_encode([$title, $paragraphs]));
        $tracking = ContentImportRecord::query()->where('tenant_id', $tenant->id)->where('source_key', $key)->first();
        $existing = $original::query()->where('tenant_id', $tenant->id)->where('slug', $original->slug)->where('locale', $locale)->first();
        $row['target'] = class_basename($original).':'.$original->slug.'@'.$locale;

        if ($tracking !== null) {
            if ($existing === null) {
                return ['disposition' => 'conflict', 'reason' => 'თარგმანი წაშლილია — თავიდან არ შეიქმნება'] + $row;
            }

            if ($this->checksum($existing) !== $tracking->local_checksum) {
                return ['disposition' => 'conflict', 'reason' => 'თარგმანი ხელით შეიცვალა — არ გადაიწერა'] + $row;
            }

            return ['disposition' => 'unchanged'] + $row;
        }

        if ($existing !== null) {
            return ['disposition' => 'conflict', 'reason' => 'ამ ენაზე გვერდი უკვე არსებობს და იმპორტით არ შექმნილა'] + $row;
        }

        $translation = $original instanceof Page
            ? new Page(['slug' => $original->slug, 'locale' => $locale, 'title' => $title, 'blocks' => [['type' => 'text', 'heading' => $title, 'body' => $paragraphs]], 'status' => Page::STATUS_DRAFT])
            : new Post(['slug' => $original->slug, 'locale' => $locale, 'title' => $title, 'body' => implode("\n\n", $paragraphs), 'status' => Post::STATUS_DRAFT]);
        $translation->tenant_id = $tenant->id;
        $translation->save();

        $tracking = new ContentImportRecord([
            'source_key' => $key,
            'source_type' => 'translation',
            'source_url' => (string) ($record['source_url'] ?? ''),
            'importable_type' => $translation::class,
            'importable_id' => $translation->id,
            'import_batch_id' => $batchId,
            'source_checksum' => $sourceChecksum,
            'local_checksum' => $this->checksum($translation),
            'review_status' => ContentImportRecord::STATUS_IMPORTED,
            'imported_at' => now(),
        ]);
        $tracking->tenant_id = $tenant->id;
        $tracking->save();

        return ['disposition' => 'imported_draft'] + $row;
    }

    /**
     * Independent of JSON key order: MySQL's JSON type re-sorts object
     * keys on save, so a block read back is equal but not identical.
     */
    public function checksum(Model $target): string
    {
        $content = $target instanceof Page ? [$target->title, $target->blocks] : [$target->getAttribute('title'), $target->getAttribute('body')];

        return hash('sha256', (string) json_encode($this->canonical($content)));
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => $this->canonical($item), $value);
    }
}
