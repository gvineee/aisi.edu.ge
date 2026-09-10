<?php

namespace Tests\Feature\ContentImport;

use App\Domain\Content\Actions\ImportLibraryLinks;
use App\Domain\Library\Models\LibraryResource;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * content-migration/library-catalog.json -> LibraryResource. Dry-run by
 * default, idempotent on external_url, never sets file_path (every source
 * entry's redistribution_rights is "unverified" — link only, never a
 * re-hosted file) — see docs/08-content-migration.md and
 * app/Domain/Library/Models/LibraryResource.php's own doc-comment contract.
 */
class ImportLibraryLinksTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(string $slug): Tenant
    {
        return Tenant::create([
            'slug' => $slug,
            'name' => ucfirst($slug),
            'locale' => 'ka',
            'timezone' => 'Asia/Tbilisi',
            'is_active' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function linkEntry(string $url, string $label = 'ნაწილი 1', string $sourcePage = 'https://aisi.edu.ge/research/i/'): array
    {
        return [
            'grade_hint' => 'საარქივო გვერდი',
            'label' => $label,
            'url' => $url,
            'source_page' => $sourcePage,
            'status' => 'metadata_review',
            'redistribution_rights' => 'unverified',
        ];
    }

    public function test_dry_run_creates_no_database_rows(): void
    {
        $tenant = $this->makeTenant('lib-dry-run');

        (new ImportLibraryLinks)->run($tenant, [$this->linkEntry('https://drive.google.com/file/d/abc/view')], commit: false);

        $this->assertSame(0, LibraryResource::query()->where('tenant_id', $tenant->id)->count());
    }

    public function test_commit_creates_a_catalog_only_external_link_resource(): void
    {
        $tenant = $this->makeTenant('lib-commit');

        $report = (new ImportLibraryLinks)->run($tenant, [$this->linkEntry('https://drive.google.com/file/d/abc/view', 'ბუნება')], commit: true);

        $this->assertSame(['created'], array_column($report, 'action'));
        $resource = LibraryResource::query()->where('tenant_id', $tenant->id)->sole();
        $this->assertSame('https://drive.google.com/file/d/abc/view', $resource->external_url);
        $this->assertSame(LibraryResource::SCOPE_CATALOG_ONLY, $resource->access_scope);
        $this->assertNull($resource->file_path);
    }

    public function test_second_commit_run_is_idempotent_on_external_url(): void
    {
        $tenant = $this->makeTenant('lib-idempotent');
        $entries = [$this->linkEntry('https://drive.google.com/file/d/abc/view')];

        (new ImportLibraryLinks)->run($tenant, $entries, commit: true);
        $report = (new ImportLibraryLinks)->run($tenant, $entries, commit: true);

        $this->assertSame(['skip_unchanged'], array_column($report, 'action'));
        $this->assertSame(1, LibraryResource::query()->where('tenant_id', $tenant->id)->count());
    }

    public function test_tenant_a_import_is_never_visible_to_tenant_b(): void
    {
        $tenantA = $this->makeTenant('lib-school-a');
        $tenantB = $this->makeTenant('lib-school-b');
        $entries = [$this->linkEntry('https://drive.google.com/file/d/abc/view')];

        (new ImportLibraryLinks)->run($tenantA, $entries, commit: true);

        $this->assertSame(1, LibraryResource::query()->where('tenant_id', $tenantA->id)->count());
        $this->assertSame(0, LibraryResource::query()->where('tenant_id', $tenantB->id)->count());
    }
}
