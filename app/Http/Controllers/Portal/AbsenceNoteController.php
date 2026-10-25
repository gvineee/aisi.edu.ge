<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Academics\Models\Student;
use App\Domain\Governance\AuditLogger;
use App\Domain\Learning\AbsenceNoteAccess;
use App\Domain\Learning\Actions\ReviewAbsenceNote;
use App\Domain\Learning\Models\AbsenceNote;
use App\Domain\Tenancy\CurrentTenant;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Absence notes: a guardian tells the school why their child is or was
 * away; the homeroom teacher or the administration approves or rejects it.
 * Approval excuses the covered absences (ReviewAbsenceNote).
 */
class AbsenceNoteController extends Controller
{
    /** How far back a note may start, and how long one may run. */
    private const MAX_DAYS_BACK = 30;

    private const MAX_SPAN_DAYS = 31;

    public function index(Request $request, CurrentTenant $currentTenant, AbsenceNoteAccess $access): Response
    {
        $tenant = $currentTenant->get();
        $userId = $request->user()->id;

        $children = GuardianLink::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->where('can_view_academic', true)
            ->whereHas('student', fn ($query) => $query->where('is_active', true))
            ->with('student')
            ->get()
            ->map(fn (GuardianLink $link) => ['id' => $link->student->id, 'name' => $link->student->fullName()])
            ->values();

        // Everything below is scoped to the viewer; someone who is neither a
        // guardian nor a reviewer simply sees the empty state.
        $isReviewer = $access->isReviewer($tenant->id, $userId);

        $myNotes = $children->isEmpty() ? collect() : AbsenceNote::query()
            ->where('tenant_id', $tenant->id)
            ->where('submitted_by', $userId)
            ->whereIn('student_id', $children->pluck('id'))
            ->with('student')
            ->latest('id')
            ->limit(30)
            ->get();

        $toReview = ! $isReviewer ? collect() : AbsenceNote::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('student_id', $access->reviewableStudents($tenant->id, $userId)->select('id'))
            ->with('student.schoolClass', 'submitter')
            ->orderByRaw('case when status = ? then 0 else 1 end', [AbsenceNote::STATUS_PENDING])
            ->latest('id')
            ->limit(50)
            ->get();

        $today = Carbon::now($tenant->timezone);

        return Inertia::render('portal/absence-notes/index', [
            'children' => $children,
            'myNotes' => $myNotes->map(fn (AbsenceNote $note) => $this->row($note, false))->values(),
            'isReviewer' => $isReviewer,
            'toReview' => $toReview->map(fn (AbsenceNote $note) => $this->row($note, true))->values(),
            'earliestDate' => $today->copy()->subDays(self::MAX_DAYS_BACK)->toDateString(),
            'today' => $today->toDateString(),
        ]);
    }

    public function store(Request $request, CurrentTenant $currentTenant, AbsenceNoteAccess $access, AuditLogger $auditLogger): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $userId = $request->user()->id;
        $earliest = Carbon::now($tenant->timezone)->subDays(self::MAX_DAYS_BACK)->toDateString();

        $data = $request->validate([
            'student_id' => ['required', 'integer'],
            'starts_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$earliest],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        abort_unless($access->guardianMayWrite($tenant->id, $userId, (int) $data['student_id']), 403);

        if (Carbon::parse($data['starts_on'])->diffInDays(Carbon::parse($data['ends_on'])) >= self::MAX_SPAN_DAYS) {
            return back()->withErrors(['ends_on' => 'ერთი შეტყობინება მაქსიმუმ 31 დღეს მოიცავს.']);
        }

        $note = new AbsenceNote([
            'student_id' => (int) $data['student_id'],
            'submitted_by' => $userId,
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'],
            'reason' => trim($data['reason']),
            'status' => AbsenceNote::STATUS_PENDING,
        ]);
        $note->tenant_id = $tenant->id;
        $note->save();

        // The reason may be health information: it is never copied into the audit log.
        $auditLogger->record($tenant->id, 'absence_note.submitted', $note, $userId, ['student_id' => $note->student_id]);

        return back()->with('toast', ['type' => 'success', 'message' => 'შეტყობინება გაიგზავნა სკოლაში.']);
    }

    public function review(Request $request, CurrentTenant $currentTenant, AbsenceNote $absenceNote, AbsenceNoteAccess $access, ReviewAbsenceNote $action): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($absenceNote->tenant_id === $tenant->id, 404);

        $student = Student::query()->where('tenant_id', $tenant->id)->whereKey($absenceNote->student_id)->firstOrFail();
        abort_unless($access->mayReview($tenant->id, $request->user()->id, $student), 403);

        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'review_note' => ['nullable', 'string', 'max:500'],
        ]);

        $excused = $action->handle($absenceNote, $request->user(), $data['decision'] === 'approve', ($data['review_note'] ?? '') !== '' ? $data['review_note'] : null);

        return back()->with('toast', ['type' => 'success', 'message' => $data['decision'] === 'approve'
            ? "დადასტურდა. საპატიოდ მოინიშნა {$excused} გაცდენა."
            : 'შეტყობინება უარყოფილია.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(AbsenceNote $note, bool $forReviewer): array
    {
        return [
            'id' => $note->id,
            'studentName' => $note->student->fullName(),
            'className' => $forReviewer ? $note->student->schoolClass?->name : null,
            'submittedBy' => $forReviewer ? $note->submitter->name : null,
            'startsOn' => $note->starts_on->toDateString(),
            'endsOn' => $note->ends_on->toDateString(),
            'reason' => $note->reason,
            'status' => $note->status,
            'reviewNote' => $note->review_note,
            'submittedAt' => $note->created_at->toIso8601String(),
        ];
    }
}
