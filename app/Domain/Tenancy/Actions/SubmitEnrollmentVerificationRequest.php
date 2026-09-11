<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Governance\AuditLogger;
use App\Domain\Tenancy\Models\EnrollmentVerificationRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records a just-registered account's claim to be a specific student, or
 * that student's guardian, and attempts to auto-resolve it against the
 * tenant's roster ({@see MatchStudentForVerification}) — an unmatched
 * request is still created (never silently dropped) so an admin can resolve
 * it by hand.
 */
class SubmitEnrollmentVerificationRequest
{
    public function __construct(
        private readonly MatchStudentForVerification $matcher,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function handle(
        int $tenantId,
        User $user,
        string $requestedRole,
        ?string $nationalId,
        string $firstName,
        string $lastName,
    ): EnrollmentVerificationRequest {
        return DB::transaction(function () use ($tenantId, $user, $requestedRole, $nationalId, $firstName, $lastName): EnrollmentVerificationRequest {
            $hasOpenRequest = EnrollmentVerificationRequest::query()
                ->where('tenant_id', $tenantId)
                ->where('user_id', $user->id)
                ->where('status', EnrollmentVerificationRequest::STATUS_PENDING)
                ->lockForUpdate()
                ->exists();

            if ($hasOpenRequest) {
                throw ValidationException::withMessages([
                    'requested_role' => 'თქვენ უკვე გაქვთ განხილვის მოლოდინში მყოფი მოთხოვნა.',
                ]);
            }

            $matched = $requestedRole === EnrollmentVerificationRequest::ROLE_STUDENT
                ? $this->matcher->forStudentRole($tenantId, $nationalId, $firstName, $lastName)
                : $this->matcher->forGuardianRole($tenantId, $nationalId, $firstName, $lastName);

            $request = new EnrollmentVerificationRequest([
                'user_id' => $user->id,
                'requested_role' => $requestedRole,
                'submitted_national_id' => $nationalId !== '' ? $nationalId : null,
                'submitted_first_name' => $firstName,
                'submitted_last_name' => $lastName,
                'matched_student_id' => $matched?->id,
                'status' => EnrollmentVerificationRequest::STATUS_PENDING,
            ]);
            $request->tenant_id = $tenantId;
            $request->save();

            $this->auditLogger->record($tenantId, 'enrollment_verification.submitted', $request, $user->id, [
                'requested_role' => $requestedRole,
                'auto_matched' => $matched !== null,
            ]);

            return $request;
        });
    }
}
