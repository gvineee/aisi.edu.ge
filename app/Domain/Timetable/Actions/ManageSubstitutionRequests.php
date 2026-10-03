<?php

namespace App\Domain\Timetable\Actions;

use App\Domain\Governance\AuditLogger;
use App\Domain\Timetable\Models\SubstitutionRequest;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Teacher-initiated coverage requests (CLAUDE-PLATFORM-MODULES §9). A
 * teacher asks; a coordinator approves (which records the StaffAbsence the
 * coverage worklist is built from) or declines with a note. Decisions lock
 * the request row so a double click cannot create two absences.
 */
class ManageSubstitutionRequests
{
    public function __construct(
        private readonly RecordStaffAbsence $recordStaffAbsence,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function submit(int $tenantId, User $teacher, Carbon $startsOn, Carbon $endsOn, ?string $reason): SubstitutionRequest
    {
        if ($endsOn->lt($startsOn)) {
            throw ValidationException::withMessages(['ends_on' => 'დასრულების თარიღი არ შეიძლება იყოს დაწყების თარიღზე ადრე.']);
        }

        $request = new SubstitutionRequest([
            'requester_id' => $teacher->id,
            'starts_on' => $startsOn->toDateString(),
            'ends_on' => $endsOn->toDateString(),
            'reason' => $reason,
            'status' => SubstitutionRequest::STATUS_PENDING,
        ]);
        $request->tenant_id = $tenantId;
        $request->save();

        $this->auditLogger->record($tenantId, 'substitution_request.submitted', $request, $teacher->id, [
            'starts_on' => $startsOn->toDateString(),
            'ends_on' => $endsOn->toDateString(),
        ]);

        return $request;
    }

    public function withdraw(SubstitutionRequest $request, User $teacher): void
    {
        DB::transaction(function () use ($request, $teacher) {
            $locked = SubstitutionRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->requester_id === $teacher->id, 403);
            $this->assertPending($locked);

            $locked->status = SubstitutionRequest::STATUS_WITHDRAWN;
            $locked->save();

            $this->auditLogger->record($locked->tenant_id, 'substitution_request.withdrawn', $locked, $teacher->id);
        });
    }

    public function decide(SubstitutionRequest $request, User $coordinator, bool $approve, ?string $note): SubstitutionRequest
    {
        return DB::transaction(function () use ($request, $coordinator, $approve, $note) {
            $locked = SubstitutionRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $this->assertPending($locked);

            if ($approve) {
                $absence = $this->recordStaffAbsence->handle(
                    tenantId: $locked->tenant_id,
                    actor: $coordinator,
                    teacher: User::query()->whereKey($locked->requester_id)->firstOrFail(),
                    startsOn: Carbon::parse($locked->starts_on->toDateString()),
                    endsOn: Carbon::parse($locked->ends_on->toDateString()),
                    reason: $locked->reason,
                );
                $locked->absence_id = $absence->id;
            }

            $locked->status = $approve ? SubstitutionRequest::STATUS_APPROVED : SubstitutionRequest::STATUS_DECLINED;
            $locked->decided_by = $coordinator->id;
            $locked->decided_at = Carbon::now();
            $locked->decision_note = $note;
            $locked->save();

            $this->auditLogger->record($locked->tenant_id, $approve ? 'substitution_request.approved' : 'substitution_request.declined', $locked, $coordinator->id, [
                'absence_id' => $locked->absence_id,
            ]);

            return $locked;
        });
    }

    private function assertPending(SubstitutionRequest $request): void
    {
        if ($request->status !== SubstitutionRequest::STATUS_PENDING) {
            throw ValidationException::withMessages(['request' => 'მოთხოვნა უკვე განხილულია.']);
        }
    }
}
