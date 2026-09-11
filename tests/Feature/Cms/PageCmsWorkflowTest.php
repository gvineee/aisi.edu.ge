<?php

namespace Tests\Feature\Cms;

use App\Domain\Content\Models\Page;
use App\Domain\Content\Models\PageRevision;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the CMS Pages screen this task adds: draft -> preview -> publish
 * -> revert to a version (CLAUDE.md Phase 1), plus the mandatory role and
 * tenant-isolation cases.
 */
class PageCmsWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function editor(): array
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;
        $editor = User::factory()->create();
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $editor->id, 'role' => TenantMembership::ROLE_EDITOR, 'is_active' => true]);

        return [$tenant, $editor];
    }

    public function test_editor_can_create_a_draft_page_and_preview_it(): void
    {
        [$tenant, $editor] = $this->editor();

        $response = $this->actingAs($editor)->post('/portal/cms/pages', [
            'slug' => 'about-us',
            'locale' => 'ka',
            'title' => 'ჩვენს შესახებ',
            'excerpt' => 'მოკლე აღწერა',
            'blocks' => json_encode([['type' => 'text', 'heading' => 'H', 'body' => 'B']]),
            'seo_title' => '',
            'seo_description' => '',
        ]);

        $page = Page::query()->where('slug', 'about-us')->firstOrFail();
        $response->assertRedirect(route('cms.pages.edit', $page));
        $this->assertSame(Page::STATUS_DRAFT, $page->status);
        $this->assertSame($tenant->id, $page->tenant_id);
        $this->assertSame(1, $page->revisions()->count());
        // Every submitted block field survives — not just `type` (guards
        // against SavePageRequest::validatedBlocks() ever regressing to
        // read from validated(), which silently drops any block field
        // without its own explicit per-field rule, e.g. heading/body).
        $this->assertSame('text', $page->blocks[0]['type']);
        $this->assertSame('H', $page->blocks[0]['heading']);
        $this->assertSame('B', $page->blocks[0]['body']);

        // A draft is not publicly reachable yet...
        $this->get('/about-us')->assertNotFound();

        // ...but the authenticated preview route renders it regardless of status.
        $this->actingAs($editor)->get(route('cms.pages.preview', $page))
            ->assertOk()
            ->assertInertia(fn ($assert) => $assert
                ->where('page.slug', 'about-us')
                ->where('preview', true));
    }

    public function test_student_role_is_forbidden_from_the_cms(): void
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;
        $student = User::factory()->create();
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $student->id, 'role' => TenantMembership::ROLE_STUDENT, 'is_active' => true]);

        $this->actingAs($student)->get('/portal/cms/pages')->assertForbidden();
        $this->actingAs($student)->post('/portal/cms/pages', [
            'slug' => 'x', 'locale' => 'ka', 'title' => 'X',
            'blocks' => json_encode([['type' => 'text', 'heading' => 'H', 'body' => 'B']]),
        ])->assertForbidden();
    }

    public function test_editor_cannot_publish_but_director_can(): void
    {
        [$tenant, $editor] = $this->editor();
        $director = User::factory()->create();
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $director->id, 'role' => TenantMembership::ROLE_DIRECTOR, 'is_active' => true]);

        $page = $tenant->pages()->create([
            'slug' => 'programs', 'locale' => 'ka', 'title' => 'პროგრამები',
            'blocks' => [], 'status' => Page::STATUS_DRAFT,
        ]);

        // Not publicly visible while draft.
        $this->get('/programs')->assertNotFound();

        $this->actingAs($editor)->post(route('cms.pages.publish', $page))->assertForbidden();
        $this->assertSame(Page::STATUS_DRAFT, $page->fresh()->status);

        $this->actingAs($director)->post(route('cms.pages.publish', $page))->assertRedirect();
        $page->refresh();
        $this->assertSame(Page::STATUS_PUBLISHED, $page->status);
        $this->assertNotNull($page->published_at);

        // Publish transition actually changes what a public visitor sees.
        $this->get('/programs')->assertOk();

        $this->actingAs($director)->post(route('cms.pages.unpublish', $page))->assertRedirect();
        $this->assertSame(Page::STATUS_DRAFT, $page->fresh()->status);
        $this->get('/programs')->assertNotFound();
    }

    public function test_restoring_a_revision_brings_back_prior_content_without_touching_current_status(): void
    {
        [$tenant, $editor] = $this->editor();

        $page = $tenant->pages()->create([
            'slug' => 'history', 'locale' => 'ka', 'title' => 'ისტორია — ვერსია 1',
            'blocks' => [['type' => 'text', 'heading' => 'V1', 'body' => 'V1 body']],
            'status' => Page::STATUS_PUBLISHED, 'published_at' => now(),
        ]);

        // First save produced a v1 revision (SavePage snapshots on every
        // save, including the initial tenant()->pages()->create() call
        // done directly here, so seed one explicitly to mirror a real save).
        $v1 = new PageRevision([
            'page_id' => $page->id, 'title' => $page->title, 'blocks' => $page->blocks,
            'status' => $page->status, 'created_by' => $editor->id,
        ]);
        $v1->tenant_id = $tenant->id;
        $v1->save();

        $this->actingAs($editor)->put(route('cms.pages.update', $page), [
            'slug' => 'history', 'locale' => 'ka', 'title' => 'ისტორია — ვერსია 2',
            'blocks' => json_encode([['type' => 'text', 'heading' => 'V2', 'body' => 'V2 body']]),
            'seo_title' => '', 'seo_description' => '',
        ])->assertRedirect();

        $page->refresh();
        $this->assertSame('ისტორია — ვერსია 2', $page->title);
        // The page's status is untouched by an ordinary content edit.
        $this->assertSame(Page::STATUS_PUBLISHED, $page->status);

        $this->actingAs($editor)->post(route('cms.pages.revisions.restore', [$page, $v1]))->assertRedirect();

        $page->refresh();
        $this->assertSame('ისტორია — ვერსია 1', $page->title);
        $this->assertSame('V1', $page->blocks[0]['heading']);
        // Restore never touches status — still published, exactly as it was
        // before the restore (RestorePageRevision's documented guarantee).
        $this->assertSame(Page::STATUS_PUBLISHED, $page->status);
    }

    public function test_tenant_isolation_on_page_edit(): void
    {
        [$tenant, $editor] = $this->editor();

        $page = $tenant->pages()->create([
            'slug' => 'contact', 'locale' => 'ka', 'title' => 'კონტაქტი',
            'blocks' => [], 'status' => Page::STATUS_DRAFT,
        ]);

        $otherTenant = Tenant::create(['slug' => 'other-school', 'name' => 'Other', 'locale' => 'ka', 'timezone' => 'Asia/Tbilisi', 'is_active' => true]);
        TenantDomain::create(['tenant_id' => $otherTenant->id, 'domain' => 'other-school.test', 'is_primary' => true]);
        $outsider = User::factory()->create();
        TenantMembership::create(['tenant_id' => $otherTenant->id, 'user_id' => $outsider->id, 'role' => TenantMembership::ROLE_ADMIN, 'is_active' => true]);

        $this->actingAs($outsider)
            ->get("http://other-school.test/portal/cms/pages/{$page->id}/edit", ['Host' => 'other-school.test'])
            ->assertNotFound();
    }
}
