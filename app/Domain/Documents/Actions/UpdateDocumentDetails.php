<?php

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\Models\Document;
use App\Domain\Governance\AuditLogger;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Metadata edits (title, category, dates, tags, description), visibility
 * and archiving. Never touches versions or approval state. The audit entry
 * lists which fields changed, not their (possibly sensitive) values.
 */
class UpdateDocumentDetails
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array{title?: string, category_id?: int|null, description?: string|null, document_date?: string|null, expires_on?: string|null, tags?: array<int, string>|null, visibility?: string}  $changes
     */
    public function update(Document $document, array $changes, User $actor): Document
    {
        return DB::transaction(function () use ($document, $changes, $actor) {
            $locked = Document::query()->where('tenant_id', $document->tenant_id)->whereKey($document->id)->lockForUpdate()->firstOrFail();

            $locked->fill(array_intersect_key($changes, array_flip(['title', 'category_id', 'description', 'document_date', 'expires_on', 'tags'])));

            if (array_key_exists('visibility', $changes)) {
                $locked->visibility = $changes['visibility'];
            }

            $dirty = array_keys($locked->getDirty());

            if ($dirty !== []) {
                $locked->save();
                $this->auditLogger->record($locked->tenant_id, 'document.details_updated', $locked, $actor->id, ['fields' => $dirty]);
            }

            return $locked;
        });
    }

    public function setArchived(Document $document, bool $archived, User $actor): void
    {
        if (($document->archived_at !== null) === $archived) {
            return;
        }

        $document->archived_at = $archived ? Carbon::now() : null;
        $document->save();

        $this->auditLogger->record($document->tenant_id, $archived ? 'document.archived' : 'document.unarchived', $document, $actor->id);
    }
}
