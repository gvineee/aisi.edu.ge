<?php

namespace App\Domain\Learning\Actions;

use App\Domain\Governance\AuditLogger;
use App\Domain\Learning\Models\AbsenceNote;
use App\Domain\Learning\Models\AttendanceRecord;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Approves or rejects a pending absence note. Approval, in one
 * transaction, turns every `absent` mark of that pupil inside the note's
 * dates into `excused`, each change audit-logged; `late` and `present`
 * marks are left as they are.
 */
class ReviewAbsenceNote
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @return int how many attendance marks became excused
     */
    public function handle(AbsenceNote $note, User $reviewer, bool $approve, ?string $reviewNote): int
    {
        return DB::transaction(function () use ($note, $reviewer, $approve, $reviewNote) {
            $note = AbsenceNote::query()->whereKey($note->id)->lockForUpdate()->firstOrFail();

            if ($note->status !== AbsenceNote::STATUS_PENDING) {
                throw ValidationException::withMessages(['note' => 'შეტყობინება უკვე განხილულია.']);
            }

            $note->status = $approve ? AbsenceNote::STATUS_APPROVED : AbsenceNote::STATUS_REJECTED;
            $note->reviewed_by = $reviewer->id;
            $note->reviewed_at = Carbon::now();
            $note->review_note = $reviewNote;
            $note->save();

            $excused = 0;

            if ($approve) {
                $records = AttendanceRecord::query()
                    ->where('tenant_id', $note->tenant_id)
                    ->where('student_id', $note->student_id)
                    ->where('status', AttendanceRecord::STATUS_ABSENT)
                    ->whereDate('occurred_on', '>=', $note->starts_on->toDateString())
                    ->whereDate('occurred_on', '<=', $note->ends_on->toDateString())
                    ->get();

                foreach ($records as $record) {
                    $record->status = AttendanceRecord::STATUS_EXCUSED;
                    $record->updated_by = $reviewer->id;
                    $record->save();
                    $this->auditLogger->record($note->tenant_id, 'attendance.updated', $record, $reviewer->id, [
                        'from' => AttendanceRecord::STATUS_ABSENT,
                        'to' => AttendanceRecord::STATUS_EXCUSED,
                        'absence_note_id' => $note->id,
                    ]);
                    $excused++;
                }
            }

            $this->auditLogger->record($note->tenant_id, $approve ? 'absence_note.approved' : 'absence_note.rejected', $note, $reviewer->id, [
                'student_id' => $note->student_id,
                'excused_marks' => $excused,
            ]);

            return $excused;
        });
    }
}
