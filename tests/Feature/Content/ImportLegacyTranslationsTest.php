<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Actions\ImportLegacyTranslations;
use App\Domain\Content\Models\ContentImportRecord;
use App\Domain\Content\Models\Page;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Old-site translations become drafts beside the Georgian original, are
 * never public, rerun unchanged, and an edited draft is never overwritten.
 */
class ImportLegacyTranslationsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Page $original;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = TenantDomain::query()->where('domain', 'localhost')->firstOrFail()->tenant;
        $this->original = $this->tenant->pages()->create([
            'slug' => 'import-wp-pages-2371', 'locale' => 'ka', 'title' => 'სკოლის ისტორია',
            'blocks' => [['type' => 'text', 'heading' => 'სკოლის ისტორია', 'body' => 'ქართული ტექსტი']],
            'status' => Page::STATUS_PUBLISHED, 'published_at' => '2023-10-03 20:56:32',
        ]);
        $record = new ContentImportRecord([
            'source_key' => 'wp-pages-2371', 'source_type' => 'pages', 'source_url' => 'https://legacy.test/2371',
            'importable_type' => Page::class, 'importable_id' => $this->original->id, 'import_batch_id' => (string) Str::uuid(), 'review_status' => 'imported',
        ]);
        $record->tenant_id = $this->tenant->id;
        $record->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function file(): array
    {
        return ['schema_version' => 1, 'records' => [
            ['translation_of' => 'wp-pages-2371', 'locale' => 'en', 'title' => 'School History', 'title_needs_review' => false, 'source_url' => 'https://legacy.test/en/history', 'paragraphs' => ['Founded in 2000.', 'Second paragraph.']],
            ['translation_of' => 'wp-pages-9999', 'locale' => 'ru', 'title' => 'Нет оригинала', 'paragraphs' => ['Текст.']],
        ]];
    }

    private function import(bool $commit): array
    {
        return app(ImportLegacyTranslations::class)->handle($this->tenant, $this->file(), $commit);
    }

    public function test_translations_become_drafts_that_never_reach_the_public_site(): void
    {
        $this->assertSame(['imported_draft', 'pending'], array_column($this->import(false), 'disposition'));
        $this->assertSame(0, Page::query()->where('locale', 'en')->count(), 'preview writes nothing');

        $this->import(true);
        $english = Page::query()->where('slug', 'import-wp-pages-2371')->where('locale', 'en')->sole();
        $this->assertSame([Page::STATUS_DRAFT, 'School History', ['Founded in 2000.', 'Second paragraph.']], [$english->status, $english->title, $english->blocks[0]['body']]);
        $this->assertSame('ქართული ტექსტი', $this->original->fresh()->blocks[0]['body'], 'the original is untouched');

        // The public page is still the Georgian one.
        $this->get('/import-wp-pages-2371')->assertOk()->assertInertia(fn ($page) => $page->where('page.title', 'სკოლის ისტორია'));

        $this->assertSame('unchanged', $this->import(true)[0]['disposition']);
    }

    public function test_an_edited_translation_is_not_overwritten(): void
    {
        $this->import(true);
        $english = Page::query()->where('locale', 'en')->sole();
        $english->update(['title' => 'Our History']);

        $this->assertSame('conflict', $this->import(true)[0]['disposition']);
        $this->assertSame('Our History', $english->fresh()->title);
    }
}
