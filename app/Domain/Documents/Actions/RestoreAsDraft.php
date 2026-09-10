<?php

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\DocumentFileStorage;
use App\Domain\Documents\Models\ApprovalRequest;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentVersion;
use App\Domain\Governance\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Restoring an old version creates a brand-new draft version copied from
 * it — it never rewrites history and never automatically re-approves
 * anything (spec §4). The restored bytes get a fresh ordinal/version row,
 * exactly like a fresh upload would.
 */
class RestoreAsDraft
{
    public function __construct(
        private readonly DocumentFileStorage $storage,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function handle(Document $document, DocumentVersion $fromVersion, User $actor): DocumentVersion
    {
        if ($fromVersion->document_id !== $document->id) {
            throw new RuntimeException('Version does not belong to this document.');
        }

        return DB::transaction(function () use ($document, $fromVersion, $actor) {
            $document = Document::query()->where('id', $document->id)->lockForUpdate()->firstOrFail();

            if ($document->latest_version_id !== null) {
                ApprovalRequest::query()
                    ->where('document_version_id', $document->latest_version_id)
                    ->where('state', ApprovalRequest::STATE_PENDING)
                    ->update(['state' => ApprovalRequest::STATE_SUPERSEDED]);
            }

            $nextOrdinal = (int) DocumentVersion::query()
                ->where('document_id', $document->id)
                ->max('ordinal') + 1;

            $copied = $this->storage->copyFrom($fromVersion, $document->id, $nextOrdinal);

            $version = new DocumentVersion([
                'document_id' => $document->id,
                'ordinal' => $nextOrdinal,
                'storage_path' => $copied['storage_path'],
                'checksum' => $copied['checksum'],
                'original_filename' => $copied['original_filename'],
                'mime' => $copied['mime'],
                'size' => $copied['size'],
                'author_id' => $actor->id,
                'scan_state' => DocumentVersion::SCAN_CLEAN,
            ]);
            $version->tenant_id = $document->tenant_id;
            $version->save();

            $document->latest_version_id = $version->id;
            $document->status = Document::STATUS_DRAFT;
            $document->save();

            $this->auditLogger->record($document->tenant_id, 'document.restored_as_draft', $document, $actor->id, [
                'restored_from_version_id' => $fromVersion->id,
                'restored_from_ordinal' => $fromVersion->ordinal,
                'new_version_id' => $version->id,
            ]);

            return $version;
        });
    }
}
