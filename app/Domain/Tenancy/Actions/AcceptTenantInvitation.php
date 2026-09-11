<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Academics\Models\TeacherAssignment;
use App\Domain\Governance\AuditLogger;
use App\Domain\Tenancy\Models\TenantInvitation;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Accepts a TenantInvitation: atomically creates the invitee's User (only
 * if none exists yet for that email), a TenantMembership for the invited
 * role, and — for a guardian or teacher invite — the matching GuardianLink
 * or TeacherAssignment. Row-locked so a token can never be accepted twice
 * (same pattern as Documents' DecideApproval / Portfolio's
 * DecidePortfolioItem).
 *
 * If a User already exists for the invited email, no password is set here
 * (that would let anyone who guesses/receives a stale invitation link
 * reset an existing account's password) — the existing account is simply
 * granted the new membership/link.
 */
class AcceptTenantInvitation
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function handle(TenantInvitation $invitation, ?string $name, ?string $password): User
    {
        return DB::transaction(function () use ($invitation, $name, $password): User {
            /** @var TenantInvitation $locked */
            $locked = TenantInvitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();

            if ($locked->isAccepted()) {
                throw new InvitationAlreadyAcceptedException("Invitation {$locked->id} was already accepted.");
            }

            if ($locked->isExpired()) {
                throw new InvitationExpiredException("Invitation {$locked->id} expired at {$locked->expires_at->toIso8601String()}.");
            }

            $user = User::query()->where('email', $locked->email)->first();

            if ($user === null) {
                $user = new User([
                    'name' => trim((string) $name) !== '' ? $name : $locked->email,
                    'email' => $locked->email,
                    'password' => $password,
                ]);
                // The invitation link itself is the proof of email ownership
                // here (only the real recipient receives it) — no separate
                // email-verification round trip is needed before granting
                // access.
                $user->email_verified_at = Carbon::now();
                $user->save();
            }

            $membership = TenantMembership::query()
                ->where('tenant_id', $locked->tenant_id)
                ->where('user_id', $user->id)
                ->where('role', $locked->role)
                ->first();

            if ($membership === null) {
                $membership = new TenantMembership([
                    'user_id' => $user->id,
                    'role' => $locked->role,
                    'is_active' => true,
                ]);
                $membership->tenant_id = $locked->tenant_id;
                $membership->save();
            } elseif (! $membership->is_active) {
                $membership->is_active = true;
                $membership->save();
            }

            if ($locked->role === TenantMembership::ROLE_GUARDIAN && $locked->student_id !== null) {
                $this->applyGuardianLink($locked, $user);
            }

            if ($locked->role === TenantMembership::ROLE_TEACHER && $locked->school_class_id !== null) {
                $this->applyTeacherAssignment($locked, $user);
            }

            $locked->accepted_at = Carbon::now();
            $locked->save();

            $this->auditLogger->record($locked->tenant_id, 'membership.invitation_accepted', $membership, $user->id, [
                'role' => $locked->role,
                'invitation_id' => $locked->id,
            ]);

            return $user;
        });
    }

    private function applyGuardianLink(TenantInvitation $invitation, User $user): void
    {
        $link = GuardianLink::query()
            ->where('tenant_id', $invitation->tenant_id)
            ->where('user_id', $user->id)
            ->where('student_id', $invitation->student_id)
            ->first();

        if ($link === null) {
            $link = new GuardianLink(['user_id' => $user->id, 'student_id' => $invitation->student_id]);
            $link->tenant_id = $invitation->tenant_id;
        }

        $link->can_view_academic = $invitation->can_view_academic;
        $link->can_view_financial = $invitation->can_view_financial;
        $link->can_pickup = $invitation->can_pickup;
        $link->can_receive_notifications = $invitation->can_receive_notifications;
        $link->is_active = true;
        $link->save();
    }

    private function applyTeacherAssignment(TenantInvitation $invitation, User $user): void
    {
        $assignment = TeacherAssignment::query()
            ->where('tenant_id', $invitation->tenant_id)
            ->where('user_id', $user->id)
            ->where('school_class_id', $invitation->school_class_id)
            ->where('subject', $invitation->subject)
            ->first();

        if ($assignment === null) {
            $assignment = new TeacherAssignment([
                'user_id' => $user->id,
                'school_class_id' => $invitation->school_class_id,
                'subject' => $invitation->subject,
            ]);
            $assignment->tenant_id = $invitation->tenant_id;
            $assignment->save();
        }
    }
}
