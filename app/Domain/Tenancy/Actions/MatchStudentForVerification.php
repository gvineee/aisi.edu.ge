<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Academics\Models\Student;

/**
 * Resolves which (if any) existing Student row a submitted identity refers
 * to — deliberately conservative: it only ever returns a match it is
 * confident about, and a Student already linked to a different portal
 * account is never offered as a match for a NEW student-role request (a
 * Student can only ever be claimed by one account). Everything else is left
 * for an admin to resolve by hand rather than guessed.
 */
class MatchStudentForVerification
{
    public function forStudentRole(int $tenantId, ?string $nationalId, string $firstName, string $lastName): ?Student
    {
        return $this->match($tenantId, $nationalId, $firstName, $lastName, requireUnclaimed: true);
    }

    public function forGuardianRole(int $tenantId, ?string $nationalId, string $firstName, string $lastName): ?Student
    {
        return $this->match($tenantId, $nationalId, $firstName, $lastName, requireUnclaimed: false);
    }

    private function match(int $tenantId, ?string $nationalId, string $firstName, string $lastName, bool $requireUnclaimed): ?Student
    {
        $nationalId = $nationalId !== null ? trim($nationalId) : null;

        if ($nationalId !== null && $nationalId !== '') {
            $student = Student::query()
                ->where('tenant_id', $tenantId)
                ->where('national_id', $nationalId)
                ->where('is_active', true)
                ->first();

            return $this->acceptIfEligible($student, $requireUnclaimed);
        }

        $candidates = Student::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereRaw('lower(first_name) = ?', [mb_strtolower(trim($firstName))])
            ->whereRaw('lower(last_name) = ?', [mb_strtolower(trim($lastName))])
            ->get();

        // A name match is only trustworthy when it's the single result —
        // Georgian schools routinely have more than one student sharing a
        // common first+last name combination.
        if ($candidates->count() !== 1) {
            return null;
        }

        return $this->acceptIfEligible($candidates->first(), $requireUnclaimed);
    }

    private function acceptIfEligible(?Student $student, bool $requireUnclaimed): ?Student
    {
        if ($student === null) {
            return null;
        }

        if ($requireUnclaimed && $student->user_id !== null) {
            return null;
        }

        return $student;
    }
}
