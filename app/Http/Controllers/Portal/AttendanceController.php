<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Governance\AuditLogger;
use App\Domain\Learning\Models\AttendanceRecord;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Timetable\Models\Lesson;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\StoreAttendanceRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
        $this->authorizeTeacher($request, $currentTenant, $lesson);

        $date = $request->string('date')->isNotEmpty()
            ? Carbon::parse((string) $request->string('date'))
            : Carbon::now($currentTenant->get()->timezone);

        $students = $lesson->schoolClass->students()
            ->where('is_active', true)
            ->orderBy('last_name')
            ->get();

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
        $this->authorizeTeacher($request, $currentTenant, $lesson);

        $tenant = $currentTenant->get();
        $occurredOn = Carbon::parse($request->validated('occurred_on'))->toDateString();
        $actorId = $request->user()->id;

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
            $record->status = $entry['status'];
            $record->comment = $entry['comment'] ?? null;
            $record->marked_by = $existing === null ? $actorId : $existing->marked_by;
            $record->updated_by = $actorId;
            $record->save();

            if ($previousStatus !== $entry['status']) {
                $auditLogger->record(
                    $tenant->id,
                    $existing ? 'attendance.updated' : 'attendance.recorded',
                    $record,
                    $actorId,
                    ['from' => $previousStatus, 'to' => $entry['status']],
                );
            }
        }

        return back();
    }

    private function authorizeTeacher(Request $request, CurrentTenant $currentTenant, Lesson $lesson): void
    {
        $tenant = $currentTenant->get();

        abort_unless(
            $lesson->tenant_id === $tenant->id && $lesson->teacher_id === $request->user()->id,
            403,
        );
    }
}
