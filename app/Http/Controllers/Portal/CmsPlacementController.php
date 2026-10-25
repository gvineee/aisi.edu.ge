<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Content\Models\ContentCategorizable;
use App\Domain\Content\Models\ContentCategory;
use App\Domain\Content\Models\Page;
use App\Domain\Content\Models\Post;
use App\Domain\Governance\AuditLogger;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * An editor moves a page or post to another site category (or out of
 * one). Only the placement changes; a placement made here is marked as
 * manual, so the placement plan never overwrites it.
 */
class CmsPlacementController extends Controller
{
    public function update(Request $request, CurrentTenant $currentTenant, string $type, int $id, AuditLogger $auditLogger): RedirectResponse
    {
        $tenantId = $currentTenant->get()->id;
        abort_unless(TenantMembership::userHasAnyActiveRole($tenantId, $request->user()->id, CmsPageController::ACCESS_ROLES), 403);

        $model = match ($type) {
            'pages' => Page::class,
            'posts' => Post::class,
            default => abort(404),
        };
        $target = $model::query()->where('tenant_id', $tenantId)->whereKey($id)->firstOrFail();

        $data = $request->validate([
            'content_category_id' => ['nullable', 'integer'],
            'section' => ['nullable', 'string', 'max:64'],
        ]);

        $category = isset($data['content_category_id'])
            ? ContentCategory::query()->where('tenant_id', $tenantId)->whereKey((int) $data['content_category_id'])->firstOrFail()
            : null;

        $placement = ContentCategorizable::query()
            ->where('categorizable_type', $target::class)
            ->where('categorizable_id', $target->id)
            ->first();
        $from = $placement?->content_category_id;

        if ($category === null) {
            $placement?->delete();
        } else {
            $placement ??= new ContentCategorizable(['categorizable_type' => $target::class, 'categorizable_id' => $target->id]);
            $placement->tenant_id = $tenantId;
            $placement->fill([
                'content_category_id' => $category->id,
                'section' => ($data['section'] ?? '') !== '' ? $data['section'] : null,
                'placed_by_source' => 'cms:user:'.$request->user()->id,
            ])->save();
        }

        $auditLogger->record($tenantId, 'content.placement_changed', $target, $request->user()->id, [
            'from_category_id' => $from,
            'to_category_id' => $category?->id,
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'კატეგორია შენახულია.']);
    }

    /**
     * Edit-form props: the tenant's categories and the item's placement.
     *
     * @return array{categories: list<array{id: int, name: string, isPublic: bool}>, placement: array{categoryId: int, section: string|null}|null}
     */
    public static function propsFor(int $tenantId, Page|Post $target): array
    {
        ContentCategory::ensureDefaults($tenantId);
        $placement = $target->placement()->first();

        return [
            'categories' => array_values(ContentCategory::query()->where('tenant_id', $tenantId)->orderBy('position')->get()
                ->map(fn (ContentCategory $category) => ['id' => $category->id, 'name' => $category->name, 'isPublic' => $category->is_public])
                ->all()),
            'placement' => $placement === null ? null : ['categoryId' => $placement->content_category_id, 'section' => $placement->section],
        ];
    }
}
