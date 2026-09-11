<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Academics\Models\Student;
use App\Domain\Governance\AuditLogger;
use App\Domain\Tenancy\Models\EnrollmentVerificationRequest;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only place a `pending` EnrollmentVerificationRequest becomes real
 * portal access. Row-locked so the same request can never be decided twice
 * concurrently (same pattern as Documents::DecideApproval /
 * Portfolio::DecidePortfolioItem / Tenancy::AcceptTenantInvitation).
 */
class DecideEnrollmentVerificationRequest
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function approve(EnrollmentVerificationRequest $request, User $reviewer, ?int $overrideStudentId): EnrollmentVerificationRequest
    {
        return DB::transaction(function () use ($request, $reviewer, $overrideStudentId): EnrollmentVerificationRequest {
            /** @var EnrollmentVerificationRequest $locked */
            $locked = EnrollmentVerificationRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isPending()) {
                throw ValidationException::withMessages([
                    'status' => 'ეს მოთხოვნა უკვე განხილულია.',
                ]);
            }

            $studentId = $locked->matched_student_id ?? $overrideStudentId;

            if ($studentId === null) {
                throw ValidationException::withMessages([
                    'student_id' => 'ავტომატური დამთხვევა ვერ მოხერხდა — მიუთითეთ მოსწავლე ხელით.',
                ]);
            }

            /** @var Student $student */
            $student = Student::query()
                ->where('tenant_id', $locked->tenant_id)
                ->where('id', $studentId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->requested_role === EnrollmentVerificationRequest::ROLE_STUDENT && $student->user_id !== null && $student->user_id !== $locked->user_id) {
                throw ValidationException::withMessages([
                    'student_id' => 'ეს მოსწავლე უკვე დაკავშირებულია სხვა ანგარიშთან.',
                ]);
            }

            $membership = TenantMembership::query()
                ->where('tenant_id', $locked->tenant_id)
                ->where('user_id', $locked->user_id)
                ->where('role', $locked->requested_role)
                ->first();

            if ($membership === null) {
                $membership = new TenantMembership([
                    'user_id' => $locked->user_id,
                    'role' => $locked->requested_role,
                    'is_active' => true,
                ]);
                $membership->tenant_id = $locked->tenant_id;
                $membership->save();
            } elseif (! $membership->is_active) {
                $membership->is_active = true;
                $membership->save();
            }

            if ($locked->requested_role === EnrollmentVerificationRequest::ROLE_STUDENT) {
                $student->user_id = $locked->user_id;
                $student->save();
            } else {
                $this->applyGuardianLink($locked, $student);
            }

            $locked->matched_student_id = $studentId;
            $locked->status = EnrollmentVerificationRequest::STATUS_APPROVED;
            $locked->reviewed_by = $reviewer->id;
            $locked->reviewed_at = Carbon::now();
            $locked->save();

            $this->auditLogger->record($locked->tenant_id, 'enrollment_verification.approved', $locked, $reviewer->id, [
                'requested_role' => $locked->requested_role,
                'student_id' => $studentId,
            ]);

            return $locked;
        });
    }

    public function reject(EnrollmentVerificationRequest $request, User $reviewer, string $reason): EnrollmentVerificationRequest
    {
        return DB::transaction(function () use ($request, $reviewer, $reason): EnrollmentVerificationRequest {
            /** @var EnrollmentVerificationRequest $locked */
            $locked = EnrollmentVerificationRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isPending()) {
                throw ValidationException::withMessages([
                    'status' => 'ეს მოთხოვნა უკვე განხილულია.',
                ]);
            }

            $locked->status = EnrollmentVerificationRequest::STATUS_REJECTED;
            $locked->reviewed_by = $reviewer->id;
            $locked->reviewed_at = Carbon::now();
            $locked->rejection_reason = $reason;
            $locked->save();

            $this->auditLogger->record($locked->tenant_id, 'enrollment_verification.rejected', $locked, $reviewer->id, [
                'reason' => $reason,
            ]);

            return $locked;
        });
    }

    private function applyGuardianLink(EnrollmentVerificationRequest $request, Student $student): void
    {
        $link = GuardianLink::query()
            ->where('tenant_id', $request->tenant_id)
            ->where('user_id', $request->user_id)
            ->where('student_id', $student->id)
            ->first();

        if ($link === null) {
            $link = new GuardianLink(['user_id' => $request->user_id, 'student_id' => $student->id]);
            $link->tenant_id = $request->tenant_id;
        }

        $link->can_view_academic = true;
        $link->can_view_financial = false;
        $link->can_pickup = false;
        $link->can_receive_notifications = true;
        $link->is_active = true;
        $link->save();
    }
}
