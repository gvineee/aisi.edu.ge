<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Governance\AuditLogger;
use App\Domain\Tenancy\Models\TenantInvitation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Re-issues a pending or expired invitation: a fresh token and a new
 * 7-day expiry on the same row. Replacing the token invalidates the link
 * in any earlier e-mail, so only the newest message works.
 */
class ResendTenantInvitation
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function handle(TenantInvitation $invitation, User $actor): TenantInvitation
    {
        if ($invitation->isAccepted()) {
            throw ValidationException::withMessages(['invitation' => 'მიღებული მოწვევის ხელახლა გაგზავნა შეუძლებელია.']);
        }

        $invitation->token = TenantInvitation::generateToken();
        $invitation->expires_at = Carbon::now()->addDays(7);
        $invitation->save();

        $this->auditLogger->record($invitation->tenant_id, 'invitation.resent', $invitation, $actor->id, [
            'email' => $invitation->email,
            'role' => $invitation->role,
        ]);

        return $invitation;
    }
}
