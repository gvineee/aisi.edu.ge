<?php

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\DocumentFileStorage;
use App\Domain\Documents\Jobs\ScanDocumentVersion;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentVersion;
use App\Domain\Documents\Models\DocumentWorkspace;
use App\Domain\Governance\AuditLogger;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Creates a document with its first version. In a workspace that requires
 * approval it starts as a draft for the teacher → director flow; otherwise
 * (plain internal files, contracts) the upload is immediately the current
 * version.
 */
class CreateDraftDocument
{
    public function __construct(
        private readonly DocumentFileStorage $storage,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array{category_id?: int|null, description?: string|null, document_date?: string|null, expires_on?: string|null, tags?: array<int, string>|null, visibility?: string|null}  $metadata
     */
    public function handle(
        int $tenantId,
        DocumentWorkspace $workspace,
        User $owner,
        string $type,
        string $title,
        UploadedFile $file,
        ?User $responsible = null,
        array $metadata = [],
    ): Document {
        $this->storage->assertAllowed($file);

        return DB::transaction(function () use ($tenantId, $workspace, $owner, $type, $title, $file, $responsible, $metadata) {
            $document = new Document([
                'workspace_id' => $workspace->id,
                'category_id' => $metadata['category_id'] ?? null,
                'owner_id' => $owner->id,
                'responsible_id' => $responsible?->id,
                'type' => $type,
                'title' => $title,
                'description' => $metadata['description'] ?? null,
                'document_date' => $metadata['document_date'] ?? null,
                'expires_on' => $metadata['expires_on'] ?? null,
                'tags' => $metadata['tags'] ?? null,
            ]);
            $document->tenant_id = $tenantId;
            $document->status = $workspace->requiresApproval() ? Document::STATUS_DRAFT : Document::STATUS_ACTIVE;
            $document->visibility = $metadata['visibility'] ?? Document::VISIBILITY_WORKSPACE;
            $document->save();

            $stored = $this->storage->store($file, $tenantId, $document->id, 1);

            $version = new DocumentVersion([
                'document_id' => $document->id,
                'ordinal' => 1,
                'storage_path' => $stored['storage_path'],
                'checksum' => $stored['checksum'],
                'original_filename' => $stored['original_filename'],
                'mime' => $stored['mime'],
                'size' => $stored['size'],
                'author_id' => $owner->id,
                'scan_state' => DocumentVersion::SCAN_PENDING,
            ]);
            $version->tenant_id = $tenantId;
            $version->save();

            $document->latest_version_id = $version->id;

            if (! $workspace->requiresApproval()) {
                $document->published_version_id = $version->id;
            }

            $document->save();

            ScanDocumentVersion::dispatch($tenantId, $version->id);

            $this->auditLogger->record($tenantId, 'document.created', $document, $owner->id, [
                'workspace_id' => $workspace->id,
                'version_id' => $version->id,
            ]);

            return $document;
        });
    }
}
