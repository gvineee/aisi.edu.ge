<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Governance\AuditLogger;
use App\Domain\Learning\Models\AbsenceNote;
use App\Domain\Learning\Models\AttendanceRecord;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Timetable\Models\Lesson;
use App\Domain\Timetable\Models\SubstitutionAssignment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\StoreAttendanceRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Only the teacher this lesson actually belongs to may view or record its
 * attendance (docs/02 critical check #3) — checked explicitly here, not
 * inferred from the UI even showing the link.
 */
class AttendanceController extends Controller
{
    public function show(Request $request, CurrentTenant $currentTenant, Lesson $lesson): Response
    {
        $date = $request->string('date')->isNotEmpty()
            ? Carbon::parse((string) $request->string('date'))
            : Carbon::now($currentTenant->get()->timezone);

        $this->authorizeTeacher($request, $currentTenant, $lesson, $date->toDateString());

        $students = $lesson->rosterQuery()->orderBy('last_name')->get();

        $existing = AttendanceRecord::query()
            ->where('tenant_id', $currentTenant->get()->id)
            ->where('lesson_id', $lesson->id)
            ->whereDate('occurred_on', $date->toDateString())
            ->get()
            ->keyBy('student_id');

        return Inertia::render('portal/attendance-register', [
            'lesson' => [
                'id' => $lesson->id,
                'subject' => $lesson->subject->name,
                'className' => $lesson->schoolClass->name,
            ],
            'date' => $date->toDateString(),
            'students' => $students->map(fn ($student) => [
                'id' => $student->id,
                'name' => $student->fullName(),
                'status' => $existing->get($student->id)?->status,
                'comment' => $existing->get($student->id)?->comment,
            ])->values(),
        ]);
    }

    public function store(StoreAttendanceRequest $request, CurrentTenant $currentTenant, Lesson $lesson, AuditLogger $auditLogger): HttpResponse
    {
        $tenant = $currentTenant->get();
        $occurredOn = Carbon::parse($request->validated('occurred_on'))->toDateString();
        $actorId = $request->user()->id;

        $this->authorizeTeacher($request, $currentTenant, $lesson, $occurredOn);

        // Only pupils this lesson is for (its class, or its group) may be marked.
        $roster = $lesson->rosterQuery()->pluck('id')->all();

        foreach ($request->validated('records') as $index => $entry) {
            if (! in_array((int) $entry['student_id'], $roster, true)) {
                throw ValidationException::withMessages(["records.{$index}.student_id" => 'მოსწავლე ამ კლასის სიაში არ არის.']);
            }
        }

        foreach ($request->validated('records') as $entry) {
            $existing = AttendanceRecord::query()
                ->where('tenant_id', $tenant->id)
                ->where('lesson_id', $lesson->id)
                ->where('student_id', $entry['student_id'])
                ->whereDate('occurred_on', $occurredOn)
                ->first();

            $previousStatus = $existing?->status;

            $record = $existing ?? new AttendanceRecord;
            $record->tenant_id = $tenant->id;
            $record->lesson_id = $lesson->id;
            $record->student_id = $entry['student_id'];
            $record->occurred_on = Carbon::parse($occurredOn);
            // An approved absence note already excuses this day.
            $record->status = $entry['status'] === AttendanceRecord::STATUS_ABSENT
                && AbsenceNote::approvedCovers($tenant->id, (int) $entry['student_id'], $occurredOn)
                ? AttendanceRecord::STATUS_EXCUSED
                : $entry['status'];
            $record->comment = $entry['comment'] ?? null;
            $record->marked_by = $existing === null ? $actorId : $existing->marked_by;
            $record->updated_by = $actorId;
            $record->save();

            if ($previousStatus !== $record->status) {
                $auditLogger->record(
                    $tenant->id,
                    $existing ? 'attendance.updated' : 'attendance.recorded',
                    $record,
                    $actorId,
                    ['from' => $previousStatus, 'to' => $record->status],
                );
            }
        }

        return back();
    }

    /**
     * The lesson's own teacher, or — only for the covered date — the
     * teacher assigned to substitute that lesson (CLAUDE-PLATFORM-MODULES
     * §9: a substitute's roster access is bound to the lesson and time).
     */
    private function authorizeTeacher(Request $request, CurrentTenant $currentTenant, Lesson $lesson, string $date): void
    {
        $tenant = $currentTenant->get();
        abort_unless($lesson->tenant_id === $tenant->id, 404);

        $userId = $request->user()->id;
        $isOwner = $lesson->teacher_id === $userId;
        $isSubstitute = SubstitutionAssignment::query()
            ->where('tenant_id', $tenant->id)
            ->where('lesson_id', $lesson->id)
            ->where('substitute_teacher_id', $userId)
            ->where('status', SubstitutionAssignment::STATUS_ASSIGNED)
            ->whereDate('date', $date)
            ->exists();

        abort_unless($isOwner || $isSubstitute, 403);
    }
}
