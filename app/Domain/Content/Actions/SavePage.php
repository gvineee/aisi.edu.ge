<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\Page;
use App\Domain\Content\Models\PageRevision;
use App\Domain\Governance\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates a new draft Page, or applies an edit to an existing one — either
 * way, every successful save snapshots the resulting state into
 * page_revisions (CLAUDE.md Phase 1: "დაბრუნება ვერსიაზე"). Never touches
 * status/published_at: that transition is ChangePageStatus's job alone, so
 * an ordinary content edit can never accidentally publish or unpublish.
 */
class SavePage
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array{slug: string, locale: string, title: string, excerpt: ?string, blocks: array<int, array<string, mixed>>, seo_title: ?string, seo_description: ?string}  $attributes
     */
    public function handle(int $tenantId, ?Page $page, array $attributes, User $actor): Page
    {
        return DB::transaction(function () use ($tenantId, $page, $attributes, $actor) {
            $isNew = $page === null;

            if ($page === null) {
                $page = new Page($attributes);
                $page->tenant_id = $tenantId;
                $page->status = Page::STATUS_DRAFT;
                $page->fill(['created_by' => $actor->id]);
            } else {
                $page->fill($attributes);
            }

            $page->fill(['updated_by' => $actor->id]);
            $page->save();

            $revision = new PageRevision([
                'page_id' => $page->id,
                'title' => $page->title,
                'excerpt' => $page->excerpt,
                'blocks' => $page->blocks,
                'status' => $page->status,
                'seo_title' => $page->seo_title,
                'seo_description' => $page->seo_description,
                'created_by' => $actor->id,
            ]);
            $revision->tenant_id = $tenantId;
            $revision->save();

            $this->auditLogger->record($tenantId, $isNew ? 'page.created' : 'page.updated', $page, $actor->id, ['slug' => $page->slug]);

            return $page;
        });
    }
}
