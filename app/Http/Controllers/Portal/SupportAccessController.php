<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Platform\Actions\ManageSupportAccess;
use App\Domain\Platform\Models\SupportAccessRequest;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The school's side of support access: only its own admin/director can
 * approve, deny or revoke a platform operator's request.
 */
class SupportAccessController extends Controller
{
    /**
     * @var array<int, string>
     */
    private const ROLES = [TenantMembership::ROLE_ADMIN, TenantMembership::ROLE_DIRECTOR];

    public function index(Request $request, CurrentTenant $currentTenant): Response
    {
        $tenant = $currentTenant->get();
        $this->authorizeRole($tenant->id, $request->user()->id);

        return Inertia::render('portal/support-access', [
            'requests' => SupportAccessRequest::query()->where('tenant_id', $tenant->id)->with('operator')->latest('id')->limit(50)->get()
                ->map(fn (SupportAccessRequest $r) => [
                    'id' => $r->id,
                    'operatorName' => $r->operator->name ?? null,
                    'reason' => $r->reason,
                    'minutes' => $r->requested_minutes,
                    'status' => $r->status,
                    'isActive' => $r->isActive(),
                    'expiresAt' => $r->expires_at?->toIso8601String(),
                    'createdAt' => $r->created_at?->toIso8601String(),
                ])->values(),
        ]);
    }

    public function decide(Request $request, CurrentTenant $currentTenant, SupportAccessRequest $supportRequest, ManageSupportAccess $support): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($supportRequest->tenant_id === $tenant->id, 404);
        $this->authorizeRole($tenant->id, $request->user()->id);
        $request->validate(['decision' => ['required', 'in:approve,deny,revoke']]);

        match ((string) $request->string('decision')) {
            'approve' => $support->approve($supportRequest, $request->user()),
            'deny' => $support->deny($supportRequest, $request->user()),
            default => $support->revoke($supportRequest, $request->user()),
        };

        return back();
    }

    private function authorizeRole(int $tenantId, int $userId): void
    {
        abort_unless(TenantMembership::userHasAnyActiveRole($tenantId, $userId, self::ROLES), 403);
    }
}
