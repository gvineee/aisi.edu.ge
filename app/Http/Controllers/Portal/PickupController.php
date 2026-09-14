<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Academics\Models\GuardianLink;
use App\Domain\PickupConsent\Actions\AddAuthorizedPickup;
use App\Domain\PickupConsent\Actions\RemoveAuthorizedPickup;
use App\Domain\PickupConsent\Models\AuthorizedPickup;
use App\Domain\Tenancy\CurrentTenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\StoreAuthorizedPickupRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "უფლებამოსილი პირები" — guardian-facing management of who else may pick up
 * their child (CLAUDE-PLATFORM-MODULES.md §7). Every action here is scoped
 * to the caller's own active {@see GuardianLink} rows that additionally carry
 * the dedicated `can_pickup` permission bit — an active link alone grants
 * academic/notification visibility, not authority over physical pickup
 * (CLAUDE.md invariant #3: guardian access needs an active link AND the
 * specific permission). A client-supplied student id is never trusted on its
 * own — see activeGuardianLink().
 */
class PickupController extends Controller
{
    public function index(Request $request, CurrentTenant $currentTenant): Response
    {
        $tenant = $currentTenant->get();
        $user = $request->user();

        $links = GuardianLink::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->where('can_pickup', true)
            ->with(['student' => fn ($query) => $query->where('is_active', true)])
            ->get()
            ->filter(fn (GuardianLink $link) => $link->student !== null);

        $children = $links->map(fn (GuardianLink $link) => $this->formatChild($tenant->id, $link))->values();

        return Inertia::render('portal/pickup/index', [
            'children' => $children,
        ]);
    }

    public function store(StoreAuthorizedPickupRequest $request, CurrentTenant $currentTenant, AddAuthorizedPickup $addAuthorizedPickup): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $user = $request->user();
        $studentId = $request->integer('student_id');

        $link = $this->activeGuardianLink($tenant->id, $studentId, $user->id);
        abort_unless($link !== null, 403);

        $addAuthorizedPickup->handle(
            student: $link->student,
            guardian: $user,
            fullName: $request->string('full_name')->toString(),
            relationship: $request->string('relationship')->toString(),
            idDocumentNumber: $request->string('id_document_number')->toString() ?: null,
        );

        return redirect()->route('pickup.index');
    }

    public function destroy(Request $request, CurrentTenant $currentTenant, AuthorizedPickup $authorizedPickup, RemoveAuthorizedPickup $removeAuthorizedPickup): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($authorizedPickup->tenant_id === $tenant->id, 404);

        $user = $request->user();
        $link = $this->activeGuardianLink($tenant->id, $authorizedPickup->student_id, $user->id);
        abort_unless($link !== null, 403);

        $removeAuthorizedPickup->handle($authorizedPickup, $user);

        return redirect()->route('pickup.index');
    }

    private function activeGuardianLink(int $tenantId, int $studentId, int $userId): ?GuardianLink
    {
        return GuardianLink::query()
            ->where('tenant_id', $tenantId)
            ->where('student_id', $studentId)
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->where('can_pickup', true)
            ->with('student')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function formatChild(int $tenantId, GuardianLink $link): array
    {
        $pickups = AuthorizedPickup::query()
            ->where('tenant_id', $tenantId)
            ->where('student_id', $link->student_id)
            ->where('is_active', true)
            ->latest('created_at')
            ->get();

        return [
            'id' => $link->student->id,
            'name' => $link->student->fullName(),
            'pickups' => $pickups->map(fn (AuthorizedPickup $pickup) => [
                'id' => $pickup->id,
                'fullName' => $pickup->full_name,
                'relationship' => $pickup->relationship,
                'idDocumentNumber' => $pickup->id_document_number,
            ])->values(),
        ];
    }
}
