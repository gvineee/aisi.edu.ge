<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Content\Actions\ChangePageStatus;
use App\Domain\Content\Actions\RestorePageRevision;
use App\Domain\Content\Actions\SavePage;
use App\Domain\Content\Models\Media;
use App\Domain\Content\Models\Page;
use App\Domain\Content\Models\PageRevision;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\SavePageRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin/director-facing CMS for Pages (docs/02 §5.1, CLAUDE.md Phase 1
 * "draft → preview → publish"). ROLE_EDITOR and ROLE_ACADEMIC_MANAGER can
 * also author/preview drafts — "რედაქტორი" ("editor") is the role this
 * screen literally exists for per the permissions matrix — but only
 * ADMIN/DIRECTOR/ACADEMIC_MANAGER may flip the publish switch (the matrix
 * gives the manager explicit "publish" rights; an editor's is described
 * only as "according to draft/publish permission", which this codebase has
 * no finer-grained mechanism for yet, so we conservatively withhold it).
 */
class CmsPageController extends Controller
{
    /**
     * @var array<int, string>
     */
    public const ACCESS_ROLES = [
        TenantMembership::ROLE_EDITOR,
        TenantMembership::ROLE_ACADEMIC_MANAGER,
        TenantMembership::ROLE_DIRECTOR,
        TenantMembership::ROLE_ADMIN,
    ];

    /**
     * @var array<int, string>
     */
    public const PUBLISH_ROLES = [
        TenantMembership::ROLE_ACADEMIC_MANAGER,
        TenantMembership::ROLE_DIRECTOR,
        TenantMembership::ROLE_ADMIN,
    ];

    public function index(Request $request, CurrentTenant $currentTenant): Response
    {
        $tenant = $currentTenant->get();
        $this->authorizeAccess($tenant->id, $request);

        $pages = Page::query()
            ->where('tenant_id', $tenant->id)
            ->when($request->string('status')->isNotEmpty(), fn ($query) => $query->where(
                'status', $request->string('status')->toString(),
            ))
            ->orderByDesc('updated_at')
            ->get();

        return Inertia::render('portal/cms/pages/index', [
            'pages' => $pages->map(fn (Page $page) => $this->formatSummary($page))->values(),
            'filters' => ['status' => $request->string('status')->toString()],
            'canPublish' => TenantMembership::userHasAnyActiveRole($tenant->id, $request->user()->id, self::PUBLISH_ROLES),
        ]);
    }

    public function create(Request $request, CurrentTenant $currentTenant): Response
    {
        $tenant = $currentTenant->get();
        $this->authorizeAccess($tenant->id, $request);

        return Inertia::render('portal/cms/pages/edit', [
            'page' => null,
            'revisions' => [],
            'media' => $this->mediaLibrary($tenant->id),
            'canPublish' => false,
        ]);
    }

    public function edit(Request $request, CurrentTenant $currentTenant, Page $page): Response
    {
        $tenant = $currentTenant->get();
        abort_unless($page->tenant_id === $tenant->id, 404);
        $this->authorizeAccess($tenant->id, $request);

        return Inertia::render('portal/cms/pages/edit', [
            'page' => $this->formatDetail($page),
            'revisions' => $page->revisions->map(fn (PageRevision $revision) => $this->formatRevision($revision))->values(),
            'media' => $this->mediaLibrary($tenant->id),
            'canPublish' => TenantMembership::userHasAnyActiveRole($tenant->id, $request->user()->id, self::PUBLISH_ROLES),
        ]);
    }

    public function store(SavePageRequest $request, CurrentTenant $currentTenant, SavePage $savePage): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $this->authorizeAccess($tenant->id, $request);

        $page = $savePage->handle($tenant->id, null, $request->pageAttributes(), $request->user());

        return redirect()->route('cms.pages.edit', $page)->with('success', 'გვერდი შეიქმნა.');
    }

    public function update(SavePageRequest $request, CurrentTenant $currentTenant, Page $page, SavePage $savePage): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($page->tenant_id === $tenant->id, 404);
        $this->authorizeAccess($tenant->id, $request);

        $savePage->handle($tenant->id, $page, $request->pageAttributes(), $request->user());

        return redirect()->route('cms.pages.edit', $page)->with('success', 'ცვლილება შენახულია.');
    }

    public function publish(Request $request, CurrentTenant $currentTenant, Page $page, ChangePageStatus $changePageStatus): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($page->tenant_id === $tenant->id, 404);
        abort_unless(TenantMembership::userHasAnyActiveRole($tenant->id, $request->user()->id, self::PUBLISH_ROLES), 403);

        $changePageStatus->handle($page, true, $request->user());

        return redirect()->route('cms.pages.edit', $page)->with('success', 'გვერდი გამოქვეყნდა.');
    }

    public function unpublish(Request $request, CurrentTenant $currentTenant, Page $page, ChangePageStatus $changePageStatus): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($page->tenant_id === $tenant->id, 404);
        abort_unless(TenantMembership::userHasAnyActiveRole($tenant->id, $request->user()->id, self::PUBLISH_ROLES), 403);

        $changePageStatus->handle($page, false, $request->user());

        return redirect()->route('cms.pages.edit', $page)->with('success', 'გვერდი მოხსნილია გამოქვეყნებიდან.');
    }

    public function restoreRevision(Request $request, CurrentTenant $currentTenant, Page $page, PageRevision $revision, RestorePageRevision $restorePageRevision): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($page->tenant_id === $tenant->id, 404);
        abort_unless($revision->tenant_id === $tenant->id, 404);
        $this->authorizeAccess($tenant->id, $request);

        $restorePageRevision->handle($page, $revision, $request->user());

        return redirect()->route('cms.pages.edit', $page)->with('success', 'ვერსია აღდგენილია.');
    }

    /**
     * Renders the exact public rendering path (same Inertia component the
     * live site uses) so "preview" is never a second, drift-prone
     * implementation of the block layout — it just allows a draft/
     * unpublished page through, which PageController::show never would.
     */
    public function preview(Request $request, CurrentTenant $currentTenant, Page $page): Response
    {
        $tenant = $currentTenant->get();
        abort_unless($page->tenant_id === $tenant->id, 404);
        $this->authorizeAccess($tenant->id, $request);

        return Inertia::render('public/page', [
            'page' => [
                'slug' => $page->slug,
                'title' => $page->title,
                'excerpt' => $page->excerpt,
                'blocks' => $page->blocks,
                'seoTitle' => $page->seo_title,
                'seoDescription' => $page->seo_description,
            ],
            'latestPosts' => [],
            'preview' => true,
        ]);
    }

    private function authorizeAccess(int $tenantId, Request $request): void
    {
        abort_unless(TenantMembership::userHasAnyActiveRole($tenantId, $request->user()->id, self::ACCESS_ROLES), 403);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mediaLibrary(int $tenantId): array
    {
        return Media::query()
            ->where('tenant_id', $tenantId)
            ->latest('created_at')
            ->limit(100)
            ->get()
            ->map(fn (Media $media) => [
                'id' => $media->id,
                'path' => $media->path,
                'url' => $media->url(),
                'originalFilename' => $media->original_filename,
                'altText' => $media->alt_text,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function formatSummary(Page $page): array
    {
        return [
            'id' => $page->id,
            'slug' => $page->slug,
            'locale' => $page->locale,
            'title' => $page->title,
            'status' => $page->status,
            'publishedAt' => $page->published_at?->toIso8601String(),
            'updatedAt' => $page->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatDetail(Page $page): array
    {
        return [
            'id' => $page->id,
            'slug' => $page->slug,
            'locale' => $page->locale,
            'title' => $page->title,
            'excerpt' => $page->excerpt,
            'blocks' => $page->blocks,
            'status' => $page->status,
            'publishedAt' => $page->published_at?->toIso8601String(),
            'seoTitle' => $page->seo_title,
            'seoDescription' => $page->seo_description,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatRevision(PageRevision $revision): array
    {
        return [
            'id' => $revision->id,
            'title' => $revision->title,
            'status' => $revision->status,
            'authorName' => $revision->creator?->name,
            'createdAt' => $revision->created_at?->toIso8601String(),
        ];
    }
}
