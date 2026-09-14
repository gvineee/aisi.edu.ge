<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Content\Actions\ChangePostStatus;
use App\Domain\Content\Actions\RestorePostRevision;
use App\Domain\Content\Actions\SavePost;
use App\Domain\Content\Models\Media;
use App\Domain\Content\Models\Post;
use App\Domain\Content\Models\PostRevision;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\SavePostRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin/director-facing CMS for Posts ("აისის ამბები") — see
 * CmsPageController's docblock for the role split rationale, identical here.
 */
class CmsPostController extends Controller
{
    /**
     * Kept identical to, but not imported from, CmsPageController::
     * ACCESS_ROLES — same duplication style as DocumentController::
     * STAFF_ROLES vs PortalContext::DOCUMENT_ACCESS_ROLES elsewhere in this
     * codebase, so each controller's authorization list is self-contained.
     *
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

        $posts = Post::query()
            ->where('tenant_id', $tenant->id)
            ->when($request->string('status')->isNotEmpty(), fn ($query) => $query->where(
                'status', $request->string('status')->toString(),
            ))
            ->orderByDesc('updated_at')
            ->get();

        return Inertia::render('portal/cms/posts/index', [
            'posts' => $posts->map(fn (Post $post) => $this->formatSummary($post))->values(),
            'filters' => ['status' => $request->string('status')->toString()],
            'canPublish' => TenantMembership::userHasAnyActiveRole($tenant->id, $request->user()->id, self::PUBLISH_ROLES),
        ]);
    }

    public function create(Request $request, CurrentTenant $currentTenant): Response
    {
        $tenant = $currentTenant->get();
        $this->authorizeAccess($tenant->id, $request);

        return Inertia::render('portal/cms/posts/edit', [
            'post' => null,
            'revisions' => [],
            'media' => $this->mediaLibrary($tenant->id),
            'canPublish' => false,
        ]);
    }

    public function edit(Request $request, CurrentTenant $currentTenant, Post $post): Response
    {
        $tenant = $currentTenant->get();
        abort_unless($post->tenant_id === $tenant->id, 404);
        $this->authorizeAccess($tenant->id, $request);

        return Inertia::render('portal/cms/posts/edit', [
            'post' => $this->formatDetail($post),
            'revisions' => $post->revisions->map(fn (PostRevision $revision) => $this->formatRevision($revision))->values(),
            'media' => $this->mediaLibrary($tenant->id),
            'canPublish' => TenantMembership::userHasAnyActiveRole($tenant->id, $request->user()->id, self::PUBLISH_ROLES),
        ]);
    }

    public function store(SavePostRequest $request, CurrentTenant $currentTenant, SavePost $savePost): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $this->authorizeAccess($tenant->id, $request);

        $post = $savePost->handle($tenant->id, null, $request->postAttributes(), $request->user());

        return redirect()->route('cms.posts.edit', $post)->with('success', 'ამბავი შეიქმნა.');
    }

    public function update(SavePostRequest $request, CurrentTenant $currentTenant, Post $post, SavePost $savePost): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($post->tenant_id === $tenant->id, 404);
        $this->authorizeAccess($tenant->id, $request);

        $savePost->handle($tenant->id, $post, $request->postAttributes(), $request->user());

        return redirect()->route('cms.posts.edit', $post)->with('success', 'ცვლილება შენახულია.');
    }

    public function publish(Request $request, CurrentTenant $currentTenant, Post $post, ChangePostStatus $changePostStatus): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($post->tenant_id === $tenant->id, 404);
        abort_unless(TenantMembership::userHasAnyActiveRole($tenant->id, $request->user()->id, self::PUBLISH_ROLES), 403);

        $changePostStatus->handle($post, true, $request->user());

        return redirect()->route('cms.posts.edit', $post)->with('success', 'ამბავი გამოქვეყნდა.');
    }

    public function unpublish(Request $request, CurrentTenant $currentTenant, Post $post, ChangePostStatus $changePostStatus): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($post->tenant_id === $tenant->id, 404);
        abort_unless(TenantMembership::userHasAnyActiveRole($tenant->id, $request->user()->id, self::PUBLISH_ROLES), 403);

        $changePostStatus->handle($post, false, $request->user());

        return redirect()->route('cms.posts.edit', $post)->with('success', 'ამბავი მოხსნილია გამოქვეყნებიდან.');
    }

    public function restoreRevision(Request $request, CurrentTenant $currentTenant, Post $post, PostRevision $revision, RestorePostRevision $restorePostRevision): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($post->tenant_id === $tenant->id, 404);
        abort_unless($revision->tenant_id === $tenant->id, 404);
        $this->authorizeAccess($tenant->id, $request);

        $restorePostRevision->handle($post, $revision, $request->user());

        return redirect()->route('cms.posts.edit', $post)->with('success', 'ვერსია აღდგენილია.');
    }

    public function preview(Request $request, CurrentTenant $currentTenant, Post $post): Response
    {
        $tenant = $currentTenant->get();
        abort_unless($post->tenant_id === $tenant->id, 404);
        $this->authorizeAccess($tenant->id, $request);

        return Inertia::render('public/news-show', [
            'post' => [
                'title' => $post->title,
                'excerpt' => $post->excerpt,
                'body' => $post->body,
                'publishedAt' => $post->published_at?->toIso8601String(),
                'seoTitle' => $post->seo_title,
                'seoDescription' => $post->seo_description,
                'coverImageUrl' => $post->coverImageUrl(),
            ],
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
    private function formatSummary(Post $post): array
    {
        return [
            'id' => $post->id,
            'slug' => $post->slug,
            'locale' => $post->locale,
            'title' => $post->title,
            'status' => $post->status,
            'publishedAt' => $post->published_at?->toIso8601String(),
            'updatedAt' => $post->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatDetail(Post $post): array
    {
        return [
            'id' => $post->id,
            'slug' => $post->slug,
            'locale' => $post->locale,
            'title' => $post->title,
            'excerpt' => $post->excerpt,
            'body' => $post->body,
            'coverImagePath' => $post->cover_image_path,
            'status' => $post->status,
            'publishedAt' => $post->published_at?->toIso8601String(),
            'seoTitle' => $post->seo_title,
            'seoDescription' => $post->seo_description,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatRevision(PostRevision $revision): array
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
