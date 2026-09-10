<?php

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\DocumentFileStorage;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentVersion;
use App\Domain\Documents\Models\DocumentWorkspace;
use App\Domain\Governance\AuditLogger;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class CreateDraftDocument
{
    public function __construct(
        private readonly DocumentFileStorage $storage,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function handle(
        int $tenantId,
        DocumentWorkspace $workspace,
        User $owner,
        string $type,
        string $title,
        UploadedFile $file,
        ?User $responsible = null,
    ): Document {
        $this->storage->assertAllowed($file);

        return DB::transaction(function () use ($tenantId, $workspace, $owner, $type, $title, $file, $responsible) {
            $document = new Document([
                'workspace_id' => $workspace->id,
                'owner_id' => $owner->id,
                'responsible_id' => $responsible?->id,
                'type' => $type,
                'title' => $title,
            ]);
            $document->tenant_id = $tenantId;
            $document->status = Document::STATUS_DRAFT;
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
                'scan_state' => DocumentVersion::SCAN_CLEAN,
            ]);
            $version->tenant_id = $tenantId;
            $version->save();

            $document->latest_version_id = $version->id;
            $document->save();

            $this->auditLogger->record($tenantId, 'document.created', $document, $owner->id, [
                'workspace_id' => $workspace->id,
                'version_id' => $version->id,
            ]);

            return $document;
        });
    }
}
