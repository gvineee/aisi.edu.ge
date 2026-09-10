<?php

namespace App\Domain\Content\Actions;

use App\Domain\Library\Models\LibraryResource;
use App\Domain\Tenancy\Models\Tenant;

/**
 * Idempotent import of content-migration/library-catalog.json (70 external
 * book/resource links discovered on the old site's /research/* archive
 * pages) into the real LibraryResource catalog. Dry-run by default; nothing
 * is ever written unless $commit is true.
 *
 * Every row is a link only — `redistribution_rights` in the source data is
 * uniformly "unverified", so `file_path` is never set and `access_scope` is
 * always `catalog_only` (metadata/link only, no re-hosted file), matching
 * LibraryResource's own doc-comment contract. `is_required` defaults false
 * since the source data doesn't confirm this.
 */
class ImportLibraryLinks
{
    /**
     * @param  array<int, array<string, mixed>>  $entries  Decoded library-catalog.json array.
     * @return array<int, array<string, mixed>> One report row per input entry.
     */
    public function run(Tenant $tenant, array $entries, bool $commit): array
    {
        $report = [];

        foreach ($entries as $entry) {
            $report[] = $this->decideAndMaybeApply($tenant, $entry, $commit);
        }

        return $report;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function decideAndMaybeApply(Tenant $tenant, array $entry, bool $commit): array
    {
        $url = (string) ($entry['url'] ?? '');
        $label = (string) ($entry['label'] ?? '');
        $sourcePage = (string) ($entry['source_page'] ?? '');

        if ($url === '') {
            return ['url' => $url, 'label' => $label, 'action' => 'skipped', 'reason' => 'entry has no url'];
        }

        $title = $label !== '' ? $label : $sourcePage;

        $existing = LibraryResource::query()
            ->where('tenant_id', $tenant->id)
            ->where('external_url', $url)
            ->first();

        if ($existing !== null) {
            return ['url' => $url, 'label' => $label, 'action' => 'skip_unchanged', 'reason' => 'already imported (matched by external_url)'];
        }

        if (! $commit) {
            return ['url' => $url, 'label' => $label, 'action' => 'create', 'reason' => "title=\"{$title}\""];
        }

        $tenant->libraryResources()->create([
            'title' => $title,
            'author' => null,
            'isbn' => null,
            'grade' => null,
            'subject' => $label !== '' ? $label : null,
            'is_required' => false,
            'access_scope' => LibraryResource::SCOPE_CATALOG_ONLY,
            'external_url' => $url,
            'file_path' => null,
        ]);

        return ['url' => $url, 'label' => $label, 'action' => 'created', 'reason' => "title=\"{$title}\""];
    }
}
