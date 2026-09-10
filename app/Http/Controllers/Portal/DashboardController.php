<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The authenticated "today" screen. Which data (and which Inertia page) a
 * user sees depends entirely on their server-resolved role for the current
 * tenant — never on anything the client claims. A user with no active
 * membership/role sees an honest empty state, not fabricated content.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, CurrentTenant $currentTenant): Response
    {
        $tenant = $currentTenant->get();
        $user = $request->user();

        $isGuardian = TenantMembership::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('role', TenantMembership::ROLE_GUARDIAN)
            ->where('is_active', true)
            ->exists();

        if ($isGuardian) {
            $links = GuardianLink::query()
                ->where('tenant_id', $tenant->id)
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->with(['student.schoolClass'])
                ->get()
                ->filter(fn (GuardianLink $link) => $link->student !== null && $link->student->is_active);

            return Inertia::render('portal/parent-dashboard', [
                'children' => $links->map(fn (GuardianLink $link) => [
                    'id' => $link->student->id,
                    'name' => $link->student->fullName(),
                    'className' => $link->student->schoolClass?->name,
                    'permissions' => [
                        'academic' => $link->can_view_academic,
                        'financial' => $link->can_view_financial,
                        'pickup' => $link->can_pickup,
                        'notifications' => $link->can_receive_notifications,
                    ],
                ])->values(),
            ]);
        }

        return Inertia::render('portal/no-role', [
            'name' => $user->name,
        ]);
    }
}
