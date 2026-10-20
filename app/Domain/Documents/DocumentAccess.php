<?php

namespace App\Domain\Documents;

use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentAccessGrant;
use App\Domain\Documents\Models\DocumentAccessLevel;
use App\Domain\Documents\Models\DocumentWorkspace;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The one place that decides who may see or change an internal document.
 * Every list, search, detail, download, upload, metadata, grant and
 * approval path asks this class; the list query is built from the same
 * rules so search can never surface a file the detail page would refuse.
 *
 * Rules:
 * - Only staff memberships (DocumentController::STAFF_ROLES) get anything;
 *   guardians and students never do.
 * - Director/admin (oversight) manage everything in their tenant.
 * - Workspace base level: `all_staff` → VIEW for every staff member (and
 *   they may add their own files); `restricted` → nothing without a grant.
 * - Grants (to a user, or to everyone holding a role) on a workspace or a
 *   single document raise the level; they never lower it.
 * - The owner always has EDIT on their own document.
 * - A document marked `restricted` drops the workspace-wide base and
 *   workspace VIEW/EDIT grants: only owner, workspace managers, oversight
 *   and document grants remain.
 * - VIEW alone sees only a document that has a published version, and only
 *   that version; EDIT+ sees drafts and every version.
 */
class DocumentAccess
{
    /**
     * @var array<int, string>
     */
    public const STAFF_ROLES = [
        TenantMembership::ROLE_TEACHER,
        TenantMembership::ROLE_ACADEMIC_MANAGER,
        TenantMembership::ROLE_ACCOUNTANT,
        TenantMembership::ROLE_EDITOR,
        TenantMembership::ROLE_LIBRARIAN,
        TenantMembership::ROLE_DIRECTOR,
        TenantMembership::ROLE_ADMIN,
    ];

    /**
     * @var array<int, string>
     */
    public const OVERSIGHT_ROLES = [TenantMembership::ROLE_DIRECTOR, TenantMembership::ROLE_ADMIN];

    /**
     * Per-request memo of a user's active staff roles, keyed "tenant:user".
     *
     * @var array<string, array<int, string>>
     */
    private array $roles = [];

    /**
     * @return array<int, string>
     */
    public function staffRoles(int $tenantId, User $user): array
    {
        $key = "{$tenantId}:{$user->id}";

        return $this->roles[$key] ??= TenantMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->whereIn('role', self::STAFF_ROLES)
            ->pluck('role')
            ->unique()
            ->values()
            ->all();
    }

    public function isStaff(int $tenantId, User $user): bool
    {
        return $this->staffRoles($tenantId, $user) !== [];
    }

    public function isOversight(int $tenantId, User $user): bool
    {
        return array_intersect($this->staffRoles($tenantId, $user), self::OVERSIGHT_ROLES) !== [];
    }

    public function workspaceLevel(DocumentWorkspace $workspace, User $user): int
    {
        $tenantId = $workspace->tenant_id;

        if (! $this->isStaff($tenantId, $user)) {
            return DocumentAccessLevel::NONE;
        }

        if ($this->isOversight($tenantId, $user)) {
            return DocumentAccessLevel::MANAGE;
        }

        $base = $workspace->isRestricted() ? DocumentAccessLevel::NONE : DocumentAccessLevel::VIEW;

        return max($base, $this->grantLevel($tenantId, $user, DocumentAccessGrant::RESOURCE_WORKSPACE, $workspace->id));
    }

    /**
     * Whether the user may add a new file to this workspace.
     */
    public function canCreateIn(DocumentWorkspace $workspace, User $user): bool
    {
        if ($workspace->archived_at !== null) {
            return false;
        }

        $level = $this->workspaceLevel($workspace, $user);

        return $workspace->isRestricted() ? $level >= DocumentAccessLevel::EDIT : $level >= DocumentAccessLevel::VIEW;
    }

    public function documentLevel(Document $document, User $user): int
    {
        $tenantId = $document->tenant_id;

        if (! $this->isStaff($tenantId, $user)) {
            return DocumentAccessLevel::NONE;
        }

        if ($this->isOversight($tenantId, $user)) {
            return DocumentAccessLevel::MANAGE;
        }

        $workspace = $document->workspace;
        $workspaceLevel = $this->workspaceLevel($workspace, $user);

        $level = max(
            $this->grantLevel($tenantId, $user, DocumentAccessGrant::RESOURCE_DOCUMENT, $document->id),
            $document->owner_id === $user->id ? DocumentAccessLevel::EDIT : DocumentAccessLevel::NONE,
            $workspaceLevel >= DocumentAccessLevel::MANAGE ? DocumentAccessLevel::MANAGE : DocumentAccessLevel::NONE,
        );

        if (! $document->isRestricted()) {
            $level = max($level, $workspaceLevel);
        }

        return $level;
    }

    public function canView(Document $document, User $user): bool
    {
        $level = $this->documentLevel($document, $user);

        return $level >= DocumentAccessLevel::EDIT
            || ($level >= DocumentAccessLevel::VIEW && $document->published_version_id !== null);
    }

    public function canEdit(Document $document, User $user): bool
    {
        return $this->documentLevel($document, $user) >= DocumentAccessLevel::EDIT;
    }

    public function canManage(Document $document, User $user): bool
    {
        return $this->documentLevel($document, $user) >= DocumentAccessLevel::MANAGE;
    }

    /**
     * Same rules as canView(), as a query constraint.
     *
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    public function constrainVisible(Builder $query, int $tenantId, User $user): Builder
    {
        $query->where('documents.tenant_id', $tenantId);

        if (! $this->isStaff($tenantId, $user)) {
            return $query->whereRaw('1 = 0');
        }

        if ($this->isOversight($tenantId, $user)) {
            return $query;
        }

        $workspaceLevels = $this->workspaceLevels($tenantId, $user);
        $byLevel = fn (int $level) => array_keys(array_filter($workspaceLevels, fn (int $l) => $l === $level));
        $documentLevels = $this->grantLevels($tenantId, $user, DocumentAccessGrant::RESOURCE_DOCUMENT);
        $documentEdit = array_keys(array_filter($documentLevels, fn (int $l) => $l >= DocumentAccessLevel::EDIT));
        $documentView = array_keys(array_filter($documentLevels, fn (int $l) => $l === DocumentAccessLevel::VIEW));

        return $query->where(function (Builder $q) use ($user, $byLevel, $documentEdit, $documentView) {
            $q->where('documents.owner_id', $user->id)
                ->orWhereIn('documents.id', $documentEdit)
                ->orWhere(fn (Builder $inner) => $inner->whereIn('documents.id', $documentView)->whereNotNull('documents.published_version_id'))
                ->orWhereIn('documents.workspace_id', $byLevel(DocumentAccessLevel::MANAGE))
                ->orWhere(fn (Builder $inner) => $inner
                    ->where('documents.visibility', '!=', Document::VISIBILITY_RESTRICTED)
                    ->where(fn (Builder $ws) => $ws
                        ->whereIn('documents.workspace_id', $byLevel(DocumentAccessLevel::EDIT))
                        ->orWhere(fn (Builder $view) => $view
                            ->whereIn('documents.workspace_id', $byLevel(DocumentAccessLevel::VIEW))
                            ->whereNotNull('documents.published_version_id'))));
        });
    }

    /**
     * Effective level for every workspace of the tenant.
     *
     * @return array<int, int> workspace id => level
     */
    public function workspaceLevels(int $tenantId, User $user): array
    {
        $grants = $this->grantLevels($tenantId, $user, DocumentAccessGrant::RESOURCE_WORKSPACE);
        $oversight = $this->isOversight($tenantId, $user);
        $staff = $this->isStaff($tenantId, $user);

        return DocumentWorkspace::query()->where('tenant_id', $tenantId)->get(['id', 'visibility'])
            ->mapWithKeys(fn (DocumentWorkspace $workspace) => [$workspace->id => match (true) {
                ! $staff => DocumentAccessLevel::NONE,
                $oversight => DocumentAccessLevel::MANAGE,
                default => max($workspace->isRestricted() ? DocumentAccessLevel::NONE : DocumentAccessLevel::VIEW, $grants[$workspace->id] ?? DocumentAccessLevel::NONE),
            }])
            ->all();
    }

    private function grantLevel(int $tenantId, User $user, string $resourceType, int $resourceId): int
    {
        return $this->grantLevels($tenantId, $user, $resourceType, $resourceId)[$resourceId] ?? DocumentAccessLevel::NONE;
    }

    /**
     * Highest grant level per resource for this user (directly or via any
     * of their active staff roles).
     *
     * @return array<int, int> resource id => level
     */
    private function grantLevels(int $tenantId, User $user, string $resourceType, ?int $resourceId = null): array
    {
        $roles = $this->staffRoles($tenantId, $user);

        $levels = [];

        DocumentAccessGrant::query()
            ->where('tenant_id', $tenantId)
            ->where('resource_type', $resourceType)
            ->when($resourceId !== null, fn ($q) => $q->where('resource_id', $resourceId))
            ->where(fn ($q) => $q->where('user_id', $user->id)->orWhereIn('role', $roles))
            ->get()
            ->each(function (DocumentAccessGrant $grant) use (&$levels) {
                $levels[$grant->resource_id] = max($levels[$grant->resource_id] ?? DocumentAccessLevel::NONE, $grant->level());
            });

        return $levels;
    }
}
