<?php

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\DocumentAccess;
use App\Domain\Documents\DocumentFileStorage;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentVersion;
use App\Domain\Documents\Models\DocumentWorkspace;
use App\Domain\Governance\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Template library (docs/07 §5 "შაბლონები"): a manager marks a document
 * with a published, scanned version as a template; staff start a new
 * document from it in a workspace they may add files to. The new document
 * gets a copy of the template's published bytes as its first version and
 * remembers which template version it came from. The template itself is
 * never changed.
 */
class ManageDocumentTemplates
{
    public function __construct(
        private readonly DocumentAccess $access,
        private readonly DocumentFileStorage $storage,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function setTemplate(Document $document, bool $isTemplate, User $actor): void
    {
        abort_unless($this->access->canManage($document, $actor), 403);

        if ($isTemplate) {
            $version = $this->publishedUsableVersion($document);

            if ($version === null || $document->archived_at !== null) {
                throw ValidationException::withMessages(['template' => 'შაბლონად მხოლოდ გამოქვეყნებული, შემოწმებული ვერსიის მქონე დოკუმენტი შეიძლება.']);
            }
        }

        $document->is_template = $isTemplate;
        $document->save();

        $this->auditLogger->record($document->tenant_id, $isTemplate ? 'document.template_marked' : 'document.template_unmarked', $document, $actor->id);
    }

    public function createFromTemplate(Document $template, DocumentWorkspace $workspace, string $title, User $actor): Document
    {
        if (! $template->is_template || $template->archived_at !== null || ! $this->access->canView($template, $actor)) {
            abort(404);
        }

        abort_unless($workspace->tenant_id === $template->tenant_id && $this->access->canCreateIn($workspace, $actor), 403);

        $source = $this->publishedUsableVersion($template);

        if ($source === null) {
            throw ValidationException::withMessages(['template' => 'შაბლონის ფაილი ახლა ხელმისაწვდომი არ არის.']);
        }

        $title = trim($title);

        if ($title === '' || mb_strlen($title) > 255) {
            throw ValidationException::withMessages(['title' => 'სათაური სავალდებულოა.']);
        }

        return DB::transaction(function () use ($template, $workspace, $title, $actor, $source) {
            $document = new Document([
                'workspace_id' => $workspace->id,
                'category_id' => $template->category_id,
                'owner_id' => $actor->id,
                'type' => $template->type,
                'title' => $title,
                'tags' => $template->tags,
            ]);
            $document->tenant_id = $workspace->tenant_id;
            $document->status = $workspace->requiresApproval() ? Document::STATUS_DRAFT : Document::STATUS_ACTIVE;
            $document->visibility = Document::VISIBILITY_WORKSPACE;
            $document->template_version_id = $source->id;
            $document->save();

            $copied = $this->storage->copyFrom($source, $document->id, 1);

            $version = new DocumentVersion([
                'document_id' => $document->id,
                'ordinal' => 1,
                'storage_path' => $copied['storage_path'],
                'checksum' => $copied['checksum'],
                'original_filename' => $copied['original_filename'],
                'mime' => $copied['mime'],
                'size' => $copied['size'],
                'author_id' => $actor->id,
                // Same bytes as the already-scanned template version.
                'scan_state' => $source->scan_state,
            ]);
            $version->tenant_id = $document->tenant_id;
            $version->save();

            $document->latest_version_id = $version->id;

            if (! $workspace->requiresApproval()) {
                $document->published_version_id = $version->id;
            }

            $document->save();

            $this->auditLogger->record($document->tenant_id, 'document.created_from_template', $document, $actor->id, [
                'template_document_id' => $template->id,
                'template_version_id' => $source->id,
                'workspace_id' => $workspace->id,
            ]);

            return $document;
        });
    }

    private function publishedUsableVersion(Document $document): ?DocumentVersion
    {
        if ($document->published_version_id === null) {
            return null;
        }

        $version = DocumentVersion::query()->where('tenant_id', $document->tenant_id)->whereKey($document->published_version_id)->first();

        return $version !== null && $version->isUsable() ? $version : null;
    }
}
