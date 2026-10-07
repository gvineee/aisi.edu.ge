<?php

namespace App\Domain\PickupConsent\Actions;

use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Academics\Models\Student;
use App\Domain\Governance\AuditLogger;
use App\Domain\PickupConsent\Models\AuthorizedPickup;
use App\Domain\PickupConsent\Models\PickupCode;
use App\Domain\PickupConsent\Models\PickupEvent;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * One-time pickup codes (CLAUDE-PLATFORM-MODULES §7): cryptographically
 * random, stored only as an HMAC, no student id inside, short validity,
 * single use with an atomic consume, and an audit trail. A separate,
 * reason-required override covers the case where no code is available.
 * Who may call which method is decided by PickupController.
 */
class PickupCodes
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const LENGTH = 8;

    public const VALID_HOURS = 4;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @return array{code: string, model: PickupCode}
     */
    public function issue(Student $student, User $guardian, ?AuthorizedPickup $person): array
    {
        if ($person !== null && ($person->student_id !== $student->id || ! $person->is_active)) {
            throw ValidationException::withMessages(['authorized_pickup_id' => 'ეს პირი ამ ბავშვის აქტიურ სიაში არ არის.']);
        }

        do {
            $code = '';

            for ($i = 0; $i < self::LENGTH; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }

            $hash = PickupCode::hash($code);
        } while (PickupCode::query()->withoutGlobalScopes()->where('code_hash', $hash)->exists());

        $model = new PickupCode([
            'student_id' => $student->id,
            'authorized_pickup_id' => $person?->id,
            'issued_by' => $guardian->id,
            'code_hash' => $hash,
            'expires_at' => Carbon::now()->addHours(self::VALID_HOURS),
        ]);
        $model->tenant_id = $student->tenant_id;
        $model->save();

        $this->auditLogger->record($student->tenant_id, 'pickup_code.issued', $model, $guardian->id, [
            'student_id' => $student->id,
            'authorized_pickup_id' => $person?->id,
        ]);

        return ['code' => $code, 'model' => $model];
    }

    /**
     * Whether the person and the issuing guardian's pickup right behind a
     * code are still in force.
     */
    private function authorityStillValid(int $tenantId, PickupCode $pickupCode): bool
    {
        if ($pickupCode->authorized_pickup_id !== null) {
            $personActive = AuthorizedPickup::query()->where('tenant_id', $tenantId)->whereKey($pickupCode->authorized_pickup_id)
                ->where('student_id', $pickupCode->student_id)->where('is_active', true)->exists();

            if (! $personActive) {
                return false;
            }
        }

        return GuardianLink::query()->where('tenant_id', $tenantId)->where('user_id', $pickupCode->issued_by)
            ->where('student_id', $pickupCode->student_id)->where('is_active', true)->where('can_pickup', true)->exists()
            && TenantMembership::userHasActiveRole($tenantId, $pickupCode->issued_by, TenantMembership::ROLE_GUARDIAN);
    }

    /**
     * Verifies and consumes a code in one atomic step. Returns only what
     * the staff member needs to release the child.
     *
     * @return array{studentName: string, className: string|null, releasedTo: string, relationship: string|null}
     */
    public function consume(int $tenantId, string $code, User $staff): array
    {
        $hash = PickupCode::hash($code);

        // One generic message for unknown, used, cancelled, expired and
        // revoked codes — no hint about which codes exist.
        $invalid = ValidationException::withMessages(['code' => 'კოდი არასწორია, გამოყენებულია ან ვადაგასულია.']);

        $result = DB::transaction(function () use ($tenantId, $hash, $staff, $invalid) {
            $pickupCode = PickupCode::query()
                ->where('tenant_id', $tenantId)
                ->where('code_hash', $hash)
                ->lockForUpdate()
                ->first();

            if ($pickupCode === null || $pickupCode->consumed_at !== null || $pickupCode->cancelled_at !== null || $pickupCode->expires_at->isPast()) {
                throw $invalid;
            }

            // Re-check the authority behind the code at release time: the
            // named person must still be authorised, and the issuing
            // guardian must still hold an active pickup-capable link and
            // membership. A revoked authority cancels the code (committed,
            // then rejected below with the same generic message).
            if (! $this->authorityStillValid($tenantId, $pickupCode)) {
                PickupCode::query()->whereKey($pickupCode->id)->whereNull('consumed_at')->update(['cancelled_at' => Carbon::now()]);
                $this->auditLogger->record($tenantId, 'pickup_code.rejected_revoked', $pickupCode, $staff->id, ['student_id' => $pickupCode->student_id]);

                return null;
            }

            $claimed = PickupCode::query()
                ->whereKey($pickupCode->id)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => Carbon::now(), 'consumed_by' => $staff->id]);

            if ($claimed !== 1) {
                throw $invalid;
            }

            $pickupCode->load('student.schoolClass', 'authorizedPickup');
            $issuer = User::query()->find($pickupCode->issued_by);

            $person = $pickupCode->authorizedPickup;
            $releasedTo = $person !== null ? $person->full_name : ($issuer !== null ? $issuer->name : '');
            $relationship = $person !== null ? $person->relationship : 'მშობელი/მეურვე';

            $event = new PickupEvent([
                'student_id' => $pickupCode->student_id,
                'pickup_code_id' => $pickupCode->id,
                'method' => PickupEvent::METHOD_CODE,
                'released_to' => $releasedTo,
                'staff_id' => $staff->id,
            ]);
            $event->tenant_id = $tenantId;
            $event->save();

            $this->auditLogger->record($tenantId, 'pickup.released_by_code', $event, $staff->id, [
                'student_id' => $pickupCode->student_id,
                'pickup_code_id' => $pickupCode->id,
            ]);

            $student = $pickupCode->student;

            return [
                // Minimal identity confirmation: first name + last initial.
                'studentName' => trim($student->first_name.' '.mb_substr($student->last_name, 0, 1).'.'),
                'className' => $student->schoolClass?->name,
                'releasedTo' => $releasedTo,
                'relationship' => $relationship,
            ];
        });

        if ($result === null) {
            throw $invalid;
        }

        return $result;
    }

    public function override(Student $student, User $staff, string $releasedTo, string $reason): PickupEvent
    {
        if (mb_strlen(trim($reason)) < 10) {
            throw ValidationException::withMessages(['reason' => 'მიუთითეთ გამონაკლისის მიზეზი (მინიმუმ 10 სიმბოლო).']);
        }

        $event = new PickupEvent([
            'student_id' => $student->id,
            'method' => PickupEvent::METHOD_OVERRIDE,
            'released_to' => trim($releasedTo),
            'reason' => trim($reason),
            'staff_id' => $staff->id,
        ]);
        $event->tenant_id = $student->tenant_id;
        $event->save();

        $this->auditLogger->record($student->tenant_id, 'pickup.override_release', $event, $staff->id, [
            'student_id' => $student->id,
        ]);

        return $event;
    }

    public function cancel(PickupCode $pickupCode, User $guardian): void
    {
        if ($pickupCode->consumed_at !== null || $pickupCode->cancelled_at !== null) {
            return;
        }

        $pickupCode->cancelled_at = Carbon::now();
        $pickupCode->save();

        $this->auditLogger->record($pickupCode->tenant_id, 'pickup_code.cancelled', $pickupCode, $guardian->id, [
            'student_id' => $pickupCode->student_id,
        ]);
    }
}
