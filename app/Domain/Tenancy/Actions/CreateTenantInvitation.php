<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Governance\AuditLogger;
use App\Domain\Tenancy\Models\TenantInvitation;
use App\Models\User;
use Illuminate\Support\Carbon;

class CreateTenantInvitation
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array{can_view_academic?: bool, can_view_financial?: bool, can_pickup?: bool, can_receive_notifications?: bool}  $guardianPermissions
     */
    public function handle(
        int $tenantId,
        User $invitedBy,
        string $email,
        string $role,
        ?int $studentId,
        ?int $schoolClassId,
        ?string $subject,
        array $guardianPermissions = [],
    ): TenantInvitation {
        $invitation = new TenantInvitation([
            'email' => mb_strtolower(trim($email)),
            'role' => $role,
            'student_id' => $studentId,
            'school_class_id' => $schoolClassId,
            'subject' => $subject,
            'can_view_academic' => $guardianPermissions['can_view_academic'] ?? true,
            'can_view_financial' => $guardianPermissions['can_view_financial'] ?? false,
            'can_pickup' => $guardianPermissions['can_pickup'] ?? false,
            'can_receive_notifications' => $guardianPermissions['can_receive_notifications'] ?? true,
            'token' => TenantInvitation::generateToken(),
            'expires_at' => Carbon::now()->addDays(7),
            'invited_by' => $invitedBy->id,
        ]);
        $invitation->tenant_id = $tenantId;
        $invitation->save();

        $this->auditLogger->record($tenantId, 'invitation.created', $invitation, $invitedBy->id, [
            'email' => $invitation->email,
            'role' => $role,
        ]);

        return $invitation;
    }
}
