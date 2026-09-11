<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\Page;
use App\Domain\Content\Models\PageRevision;
use App\Domain\Governance\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Restores a Page's content fields (title/excerpt/blocks/seo) from an old
 * revision. Deliberately never restores the revision's `status` — an
 * editor without publish rights could otherwise use "restore" to silently
 * republish a page that was later unpublished, bypassing ChangePageStatus's
 * own authorization gate. The restore itself is recorded as a brand-new
 * revision, same as any other save, so history stays a simple append-only
 * log rather than something restore rewrites.
 */
class RestorePageRevision
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function handle(Page $page, PageRevision $revision, User $actor): Page
    {
        if ($revision->page_id !== $page->id) {
            throw new RuntimeException('Revision does not belong to this page.');
        }

        return DB::transaction(function () use ($page, $revision, $actor) {
            $page->title = $revision->title;
            $page->excerpt = $revision->excerpt;
            $page->blocks = $revision->blocks;
            $page->seo_title = $revision->seo_title;
            $page->seo_description = $revision->seo_description;
            $page->fill(['updated_by' => $actor->id]);
            $page->save();

            $newRevision = new PageRevision([
                'page_id' => $page->id,
                'title' => $page->title,
                'excerpt' => $page->excerpt,
                'blocks' => $page->blocks,
                'status' => $page->status,
                'seo_title' => $page->seo_title,
                'seo_description' => $page->seo_description,
                'created_by' => $actor->id,
            ]);
            $newRevision->tenant_id = $page->tenant_id;
            $newRevision->save();

            $this->auditLogger->record($page->tenant_id, 'page.restored', $page, $actor->id, ['restored_from_revision_id' => $revision->id]);

            return $page;
        });
    }
}
