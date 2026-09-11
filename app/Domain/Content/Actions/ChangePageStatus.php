<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\Page;
use App\Domain\Governance\AuditLogger;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only place a Page's status/published_at ever changes — audit-logged
 * actor+timestamp per CLAUDE.md invariant #7 (a dedicated published_by
 * column would duplicate what the audit trail already records).
 */
class ChangePageStatus
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function handle(Page $page, bool $publish, User $actor): Page
    {
        return DB::transaction(function () use ($page, $publish, $actor) {
            /** @var Page $locked */
            $locked = Page::query()->whereKey($page->id)->lockForUpdate()->firstOrFail();

            $locked->status = $publish ? Page::STATUS_PUBLISHED : Page::STATUS_DRAFT;
            $locked->published_at = $publish ? Carbon::now() : null;
            $locked->fill(['updated_by' => $actor->id]);
            $locked->save();

            $this->auditLogger->record($locked->tenant_id, $publish ? 'page.published' : 'page.unpublished', $locked, $actor->id, ['slug' => $locked->slug]);

            return $locked;
        });
    }
}
