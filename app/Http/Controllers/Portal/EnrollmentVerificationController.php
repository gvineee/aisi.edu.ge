<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Tenancy\Actions\DecideEnrollmentVerificationRequest;
use App\Domain\Tenancy\Actions\SubmitEnrollmentVerificationRequest;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\EnrollmentVerificationRequest;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\StoreEnrollmentVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Self-service "I am this student" / "I am this student's guardian" claim
 * for a just-registered account that has no active role in this tenant yet.
 * Submitting never grants access by itself — see
 * {@see DecideEnrollmentVerificationRequest}.
 */
class EnrollmentVerificationController extends Controller
{
    public function show(Request $request, CurrentTenant $currentTenant): Response
    {
        $tenant = $currentTenant->get();
        $user = $request->user();

        $latest = EnrollmentVerificationRequest::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->latest('created_at')
            ->first();

        return Inertia::render('portal/verify-enrollment', [
            'existingRequest' => $latest === null ? null : [
                'status' => $latest->status,
                'requestedRole' => $latest->requested_role,
                'submittedName' => $latest->submittedFullName(),
                'rejectionReason' => $latest->rejection_reason,
            ],
        ]);
    }

    public function store(StoreEnrollmentVerificationRequest $request, CurrentTenant $currentTenant, SubmitEnrollmentVerificationRequest $action): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $user = $request->user();

        $action->handle(
            tenantId: $tenant->id,
            user: $user,
            requestedRole: $request->string('requested_role')->toString(),
            nationalId: $request->string('national_id')->toString() ?: null,
            firstName: $request->string('first_name')->toString(),
            lastName: $request->string('last_name')->toString(),
        );

        return redirect()->route('enrollment-verification.show')->with('toast', [
            'type' => 'success',
            'message' => 'მოთხოვნა გაიგზავნა განსახილველად.',
        ]);
    }
}
