<?php

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\Models\DocumentCategory;
use App\Domain\Documents\Models\DocumentWorkspace;
use App\Domain\Governance\AuditLogger;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Workspaces (folders such as "ხელშეკრულებები", "შიდა დოკუმენტაცია") and
 * categories, managed by director/admin. Nothing is ever hard-deleted:
 * archiving hides a workspace/category from new uploads while existing
 * documents keep it.
 */
class ManageDocumentLibrary
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array{title: string, description?: string|null, classification: string, visibility: string, requires_approval: bool, is_confidential: bool}  $data
     */
    public function saveWorkspace(int $tenantId, ?DocumentWorkspace $workspace, array $data, User $actor): DocumentWorkspace
    {
        $duplicate = DocumentWorkspace::query()->where('tenant_id', $tenantId)->where('title', $data['title'])
            ->when($workspace !== null, fn ($q) => $q->whereKeyNot($workspace->id))->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['title' => 'ასეთი სახელის სივრცე უკვე არსებობს.']);
        }

        $isNew = $workspace === null;
        $workspace ??= new DocumentWorkspace(['created_by' => $actor->id]);
        $workspace->tenant_id = $tenantId;
        $workspace->fill([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'classification' => $data['classification'],
            'visibility' => $data['visibility'],
            'requires_approval' => $data['requires_approval'],
            'is_confidential' => $data['is_confidential'],
        ]);
        $dirty = array_keys($workspace->getDirty());
        $workspace->save();

        $this->auditLogger->record($tenantId, $isNew ? 'document_workspace.created' : 'document_workspace.updated', $workspace, $actor->id, ['fields' => $dirty]);

        return $workspace;
    }

    public function setWorkspaceArchived(DocumentWorkspace $workspace, bool $archived, User $actor): void
    {
        if (($workspace->archived_at !== null) === $archived) {
            return;
        }

        $workspace->archived_at = $archived ? Carbon::now() : null;
        $workspace->save();

        $this->auditLogger->record($workspace->tenant_id, $archived ? 'document_workspace.archived' : 'document_workspace.unarchived', $workspace, $actor->id);
    }

    public function saveCategory(int $tenantId, ?DocumentCategory $category, string $name, int $sortOrder, ?bool $archived, User $actor): DocumentCategory
    {
        $duplicate = DocumentCategory::query()->where('tenant_id', $tenantId)->where('name', $name)
            ->when($category !== null, fn ($q) => $q->whereKeyNot($category->id))->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['name' => 'ასეთი კატეგორია უკვე არსებობს.']);
        }

        $isNew = $category === null;
        $category ??= new DocumentCategory;
        $category->tenant_id = $tenantId;
        $category->name = $name;
        $category->sort_order = $sortOrder;

        if ($archived !== null) {
            $category->archived_at = $archived ? ($category->archived_at ?? Carbon::now()) : null;
        }

        $category->save();

        $this->auditLogger->record($tenantId, $isNew ? 'document_category.created' : 'document_category.updated', $category, $actor->id, ['name' => $name]);

        return $category;
    }
}
