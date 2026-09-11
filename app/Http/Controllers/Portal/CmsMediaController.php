<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Content\Actions\StoreMedia;
use App\Domain\Content\Models\Media;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\StoreMediaRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Media library screen (docs/08-content-migration.md §6.9's asset
 * classification note): list what's uploaded, upload a new image/PDF, so a
 * page/post editor has something real to insert a URL from instead of
 * pasting an arbitrary external link.
 */
class CmsMediaController extends Controller
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

    public function index(Request $request, CurrentTenant $currentTenant): Response
    {
        $tenant = $currentTenant->get();
        abort_unless(TenantMembership::userHasAnyActiveRole($tenant->id, $request->user()->id, self::ACCESS_ROLES), 403);

        $media = Media::query()
            ->where('tenant_id', $tenant->id)
            ->latest('created_at')
            ->get();

        return Inertia::render('portal/cms/media/index', [
            'media' => $media->map(fn (Media $item) => $this->format($item))->values(),
        ]);
    }

    public function store(StoreMediaRequest $request, CurrentTenant $currentTenant, StoreMedia $storeMedia): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless(TenantMembership::userHasAnyActiveRole($tenant->id, $request->user()->id, self::ACCESS_ROLES), 403);

        $storeMedia->handle(
            $tenant->id,
            $request->file('file'),
            $request->user(),
            $request->string('alt_text')->toString() ?: null,
        );

        return redirect()->route('cms.media.index')->with('success', 'ფაილი აიტვირთა.');
    }

    /**
     * @return array<string, mixed>
     */
    private function format(Media $media): array
    {
        return [
            'id' => $media->id,
            'path' => $media->path,
            'url' => $media->url(),
            'mime' => $media->mime,
            'size' => $media->size,
            'originalFilename' => $media->original_filename,
            'altText' => $media->alt_text,
            'createdAt' => $media->created_at?->toIso8601String(),
        ];
    }
}
