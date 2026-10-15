<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Documents\Actions\ManageDocumentAccess;
use App\Domain\Documents\DocumentAccess;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentAccessGrant;
use App\Domain\Documents\Models\DocumentAccessLevel;
use App\Domain\Documents\Models\DocumentWorkspace;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Distributes access to a workspace or a single document: to one staff
 * member or to everyone holding a staff role, at view / edit / manage
 * level. Only someone who manages that resource may change its grants.
 */
class DocumentAccessGrantController extends Controller
{
    public function __construct(private readonly DocumentAccess $access) {}

    public function store(Request $request, CurrentTenant $currentTenant, ManageDocumentAccess $action): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $data = $request->validate([
            'resource_type' => ['required', Rule::in([DocumentAccessGrant::RESOURCE_WORKSPACE, DocumentAccessGrant::RESOURCE_DOCUMENT])],
            'resource_id' => ['required', 'integer'],
            'user_id' => ['nullable', 'integer', 'required_without:role'],
            'role' => ['nullable', 'string', 'required_without:user_id'],
            'permission' => ['required', Rule::in(DocumentAccessGrant::LEVELS)],
        ]);

        $this->authorizeResource($tenant->id, $request->user(), $data['resource_type'], (int) $data['resource_id']);

        $action->grant(
            $tenant->id,
            $data['resource_type'],
            (int) $data['resource_id'],
            isset($data['user_id']) ? (int) $data['user_id'] : null,
            isset($data['user_id']) ? null : ($data['role'] ?? null),
            $data['permission'],
            $request->user(),
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'წვდომა მიენიჭა.']);
    }

    public function destroy(Request $request, CurrentTenant $currentTenant, DocumentAccessGrant $documentAccessGrant, ManageDocumentAccess $action): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($documentAccessGrant->tenant_id === $tenant->id, 404);
        $this->authorizeResource($tenant->id, $request->user(), $documentAccessGrant->resource_type, $documentAccessGrant->resource_id);

        $action->revoke($documentAccessGrant, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'წვდომა გაუქმდა.']);
    }

    /**
     * @return array<int, array{id: int, userId: int|null, userName: string|null, role: string|null, roleLabel: string|null, permission: string}>
     */
    public static function grantsFor(int $tenantId, string $resourceType, int $resourceId): array
    {
        return DocumentAccessGrant::query()->where('tenant_id', $tenantId)
            ->where('resource_type', $resourceType)->where('resource_id', $resourceId)
            ->with('user')->orderBy('id')->get()
            ->map(fn (DocumentAccessGrant $grant) => [
                'id' => $grant->id,
                'userId' => $grant->user_id,
                'userName' => $grant->user?->name,
                'role' => $grant->role,
                'roleLabel' => $grant->role !== null ? (self::roleOptions()[$grant->role] ?? $grant->role) : null,
                'permission' => match ($grant->permission) {
                    DocumentAccessGrant::PERMISSION_CONTRIBUTOR => DocumentAccessGrant::PERMISSION_EDIT,
                    DocumentAccessGrant::PERMISSION_APPROVER => DocumentAccessGrant::PERMISSION_MANAGE,
                    default => $grant->permission,
                },
            ])->values()->all();
    }

    /**
     * Active staff members a grant can target.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public static function staffOptions(int $tenantId): array
    {
        return User::query()
            ->whereIn('id', TenantMembership::query()->where('tenant_id', $tenantId)->where('is_active', true)
                ->whereIn('role', DocumentAccess::STAFF_ROLES)->select('user_id'))
            ->orderBy('name')->get(['id', 'name'])
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])->values()->all();
    }

    /**
     * @return array<string, string>
     */
    public static function roleOptions(): array
    {
        return [
            TenantMembership::ROLE_TEACHER => 'ყველა მასწავლებელი',
            TenantMembership::ROLE_ACADEMIC_MANAGER => 'აკადემიური მენეჯერები',
            TenantMembership::ROLE_ACCOUNTANT => 'ბუღალტერია',
            TenantMembership::ROLE_EDITOR => 'რედაქტორები',
            TenantMembership::ROLE_DIRECTOR => 'დირექცია',
            TenantMembership::ROLE_ADMIN => 'ადმინისტრატორები',
        ];
    }

    private function authorizeResource(int $tenantId, User $user, string $resourceType, int $resourceId): void
    {
        if ($resourceType === DocumentAccessGrant::RESOURCE_WORKSPACE) {
            $workspace = DocumentWorkspace::query()->where('tenant_id', $tenantId)->findOrFail($resourceId);
            abort_unless($this->access->workspaceLevel($workspace, $user) >= DocumentAccessLevel::MANAGE, 403);

            return;
        }

        $document = Document::query()->where('tenant_id', $tenantId)->findOrFail($resourceId);
        abort_unless($this->access->canManage($document, $user), 403);
    }
}
