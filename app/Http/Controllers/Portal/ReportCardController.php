<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\Student;
use App\Domain\Academics\Models\TeacherAssignment;
use App\Domain\Assessment\Actions\GenerateReportCards;
use App\Domain\Assessment\Actions\ReportCardWorkflow;
use App\Domain\Assessment\Models\AssessmentPeriod;
use App\Domain\Assessment\Models\ReportCard;
use App\Domain\Assessment\Models\ReportCardApproval;
use App\Domain\Assessment\Models\ReportCardComment;
use App\Domain\Assessment\Models\ReportCardPublication;
use App\Domain\Assessment\Models\ReportCardSubject;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "ტაბელები" — report card workflow (CLAUDE-PLATFORM-MODULES §6).
 *
 * Who does what (re-checked on every request, nav visibility is not the
 * boundary):
 *  - academic manager / director / admin: generate cards for a closed
 *    period, see every card;
 *  - subject teacher: fills only their own subject lines while the card is
 *    a draft, and sees only those lines;
 *  - homeroom teacher of the class (or academic manager): reviews a card
 *    whose lines are all complete, with a required comment;
 *  - director: approves; director / academic manager / admin publish;
 *  - director / academic manager: return for changes, open a revision;
 *  - student, guardian with an active `can_view_academic` link: only the
 *    published snapshots.
 */
class ReportCardController extends Controller
{
    /**
     * @var array<int, string>
     */
    private const MANAGEMENT_ROLES = [
        TenantMembership::ROLE_ACADEMIC_MANAGER,
        TenantMembership::ROLE_DIRECTOR,
        TenantMembership::ROLE_ADMIN,
    ];

    /**
     * @var array<string, string>
     */
    private const LABELS = [
        ReportCard::STATUS_DRAFT => 'მასწავლებლები ავსებენ',
        ReportCard::STATUS_TEACHER_COMPLETE => 'დამრიგებლის განხილვას ელის',
        ReportCard::STATUS_HOMEROOM_REVIEW => 'დირექტორის დამტკიცებას ელის',
        ReportCard::STATUS_DIRECTOR_APPROVED => 'დამტკიცებულია, გამოსაქვეყნებელია',
        ReportCard::STATUS_PUBLISHED => 'გამოქვეყნებულია',
    ];

    public function index(Request $request, CurrentTenant $currentTenant): Response
    {
        $tenant = $currentTenant->get();
        $user = $request->user();

        $isManagement = $this->hasAny($tenant->id, $user->id, self::MANAGEMENT_ROLES);
        $isTeacher = $this->has($tenant->id, $user->id, TenantMembership::ROLE_TEACHER);
        $isDirector = $this->has($tenant->id, $user->id, TenantMembership::ROLE_DIRECTOR);

        abort_unless($isManagement || $isTeacher, 403);

        $management = null;

        if ($isManagement) {
            $classes = SchoolClass::query()->where('tenant_id', $tenant->id)->orderBy('name')->get();
            $periods = AssessmentPeriod::query()->where('tenant_id', $tenant->id)->orderByDesc('starts_on')->get();
            $classId = $request->integer('class') ?: $classes->first()?->id;
            $periodId = $request->integer('period') ?: $periods->first()?->id;

            // A missing class/period id matches no rows (ids start at 1).
            $cards = ReportCard::query()
                ->where('tenant_id', $tenant->id)
                ->where('school_class_id', $classId ?? 0)
                ->where('assessment_period_id', $periodId ?? 0)
                ->with('student')
                ->withCount(['subjects', 'subjects as completed_subjects_count' => fn ($query) => $query->whereNotNull('completed_at')])
                ->get()
                ->sortBy(fn (ReportCard $card) => $card->student->fullName());

            $management = [
                'classes' => $classes->map(fn (SchoolClass $class) => ['id' => $class->id, 'name' => $class->name])->values(),
                'periods' => $periods->map(fn (AssessmentPeriod $period) => [
                    'id' => $period->id, 'name' => $period->name, 'isClosed' => $period->isClosed(),
                ])->values(),
                'selectedClassId' => $classId,
                'selectedPeriodId' => $periodId,
                'cards' => $cards->map(fn (ReportCard $card) => [
                    'id' => $card->id,
                    'studentName' => $card->student->fullName(),
                    'status' => $card->status,
                    'statusLabel' => self::LABELS[$card->status] ?? $card->status,
                    'revision' => $card->revision,
                    'subjectsCount' => $card->subjects_count,
                    'completedSubjectsCount' => $card->completed_subjects_count,
                ])->values(),
            ];
        }

        $myLines = $isTeacher
            ? ReportCardSubject::query()
                ->where('tenant_id', $tenant->id)
                ->whereIn('teacher_assignment_id', $this->teacherAssignmentIds($tenant->id, $user->id))
                ->whereHas('reportCard', fn ($query) => $query->where('status', ReportCard::STATUS_DRAFT))
                ->with('reportCard.student', 'reportCard.period', 'reportCard.schoolClass')
                ->get()
                ->map(fn (ReportCardSubject $line) => [
                    'reportCardId' => $line->report_card_id,
                    'studentName' => $line->reportCard->student->fullName(),
                    'className' => $line->reportCard->schoolClass->name,
                    'periodName' => $line->reportCard->period->name,
                    'subject' => $line->subject_label,
                    'isComplete' => $line->isComplete(),
                ])
                ->sortBy([['isComplete', 'asc'], ['studentName', 'asc']])
                ->values()
            : collect();

        $homeroomQueue = ReportCard::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', ReportCard::STATUS_TEACHER_COMPLETE)
            ->when(! $this->has($tenant->id, $user->id, TenantMembership::ROLE_ACADEMIC_MANAGER), fn ($query) => $query
                ->whereIn('school_class_id', SchoolClass::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('homeroom_teacher_id', $isTeacher ? $user->id : 0)
                    ->select('id')))
            ->with('student', 'schoolClass', 'period')
            ->get();

        $directorQueue = $isDirector
            ? ReportCard::query()
                ->where('tenant_id', $tenant->id)
                ->whereIn('status', [ReportCard::STATUS_HOMEROOM_REVIEW, ReportCard::STATUS_DIRECTOR_APPROVED])
                ->with('student', 'schoolClass', 'period')
                ->get()
            : collect();

        return Inertia::render('portal/report-cards/index', [
            'management' => $management,
            'myLines' => $myLines,
            'homeroomQueue' => $homeroomQueue->map(fn (ReportCard $card) => $this->queueRow($card))->values(),
            'directorQueue' => $directorQueue->map(fn (ReportCard $card) => $this->queueRow($card))->values(),
        ]);
    }

    public function generate(Request $request, CurrentTenant $currentTenant, GenerateReportCards $generate): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($this->hasAny($tenant->id, $request->user()->id, self::MANAGEMENT_ROLES), 403);

        $request->validate([
            'school_class_id' => ['required', 'integer'],
            'assessment_period_id' => ['required', 'integer'],
        ]);

        $schoolClass = SchoolClass::query()->where('tenant_id', $tenant->id)->whereKey($request->integer('school_class_id'))->firstOrFail();
        $period = AssessmentPeriod::query()->where('tenant_id', $tenant->id)->whereKey($request->integer('assessment_period_id'))->firstOrFail();

        $result = $generate->handle($schoolClass, $period, $request->user());

        return redirect()
            ->route('report-cards.index', ['class' => $schoolClass->id, 'period' => $period->id])
            ->with('toast', ['type' => 'success', 'message' => "შეიქმნა {$result['created']}, განახლდა {$result['refreshed']}."]);
    }

    public function show(Request $request, CurrentTenant $currentTenant, ReportCard $reportCard): Response
    {
        $tenant = $currentTenant->get();
        abort_unless($reportCard->tenant_id === $tenant->id, 404);

        $user = $request->user();
        $reportCard->load('student', 'schoolClass', 'period', 'subjects', 'comments.author', 'approvals.actor', 'publications');

        $access = $this->staffAccess($tenant->id, $user, $reportCard);
        abort_unless($access['seesAll'] || $access['ownTeacherAssignmentIds'] !== [], 403);

        $visibleLines = $access['seesAll']
            ? $reportCard->subjects
            : $reportCard->subjects->whereIn('teacher_assignment_id', $access['ownTeacherAssignmentIds']);

        $status = $reportCard->status;

        return Inertia::render('portal/report-cards/show', [
            'card' => [
                'id' => $reportCard->id,
                'studentName' => $reportCard->student->fullName(),
                'className' => $reportCard->schoolClass->name,
                'periodName' => $reportCard->period->name,
                'status' => $status,
                'statusLabel' => self::LABELS[$status] ?? $status,
                'revision' => $reportCard->revision,
                'homeroomComment' => $reportCard->latestComment(ReportCardComment::KIND_HOMEROOM)?->body,
                'directorComment' => $reportCard->latestComment(ReportCardComment::KIND_DIRECTOR)?->body,
            ],
            'lines' => $visibleLines->map(fn (ReportCardSubject $line) => [
                'id' => $line->id,
                'subject' => $line->subject_label,
                'assessmentsCount' => $line->assessments_count,
                'averagePercent' => $line->average_percent,
                'finalMark' => $line->final_mark,
                'teacherComment' => $line->teacher_comment,
                'isComplete' => $line->isComplete(),
                'canEdit' => $status === ReportCard::STATUS_DRAFT && in_array($line->teacher_assignment_id, $access['ownTeacherAssignmentIds'], true),
            ])->values(),
            'history' => $access['seesAll'] ? $reportCard->approvals->map(fn (ReportCardApproval $approval) => [
                'revision' => $approval->revision,
                'from' => self::LABELS[$approval->from_status] ?? $approval->from_status,
                'to' => self::LABELS[$approval->to_status] ?? $approval->to_status,
                'note' => $approval->note,
                'actorName' => $approval->actor->name ?? null,
                'createdAt' => $approval->created_at?->toIso8601String(),
            ])->values() : [],
            'publications' => $access['seesAll'] ? $reportCard->publications->map(fn (ReportCardPublication $publication) => [
                'id' => $publication->id,
                'revision' => $publication->revision,
                'publishedAt' => $publication->published_at->toIso8601String(),
            ])->values() : [],
            'can' => [
                'homeroomSubmit' => $status === ReportCard::STATUS_TEACHER_COMPLETE && $access['isHomeroomReviewer'],
                'approve' => $status === ReportCard::STATUS_HOMEROOM_REVIEW && $access['isDirector'],
                'publish' => $status === ReportCard::STATUS_DIRECTOR_APPROVED && $access['isPublisher'],
                'returnForChanges' => $this->mayReturn($status, $access),
                'revise' => $status === ReportCard::STATUS_PUBLISHED && $access['isSeniorReviewer'],
            ],
            'returnableLines' => $access['seesAll'] ? $reportCard->subjects->map(fn (ReportCardSubject $line) => [
                'id' => $line->id, 'subject' => $line->subject_label,
            ])->values() : [],
        ]);
    }

    public function saveLine(Request $request, CurrentTenant $currentTenant, ReportCard $reportCard, ReportCardSubject $line, ReportCardWorkflow $workflow): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($reportCard->tenant_id === $tenant->id, 404);
        abort_unless($line->tenant_id === $tenant->id && $line->report_card_id === $reportCard->id, 404);

        $user = $request->user();
        abort_unless(in_array($line->teacher_assignment_id, $this->teacherAssignmentIds($tenant->id, $user->id), true), 403);

        $request->validate([
            'final_mark' => ['nullable', 'string', 'max:32'],
            'teacher_comment' => ['nullable', 'string', 'max:2000'],
            'complete' => ['boolean'],
        ]);

        $workflow->saveSubject($line, $user, $request->string('final_mark')->toString() ?: null, $request->string('teacher_comment')->toString() ?: null, $request->boolean('complete'));

        return back();
    }

    public function homeroomSubmit(Request $request, CurrentTenant $currentTenant, ReportCard $reportCard, ReportCardWorkflow $workflow): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($reportCard->tenant_id === $tenant->id, 404);
        abort_unless($this->staffAccess($tenant->id, $request->user(), $reportCard)['isHomeroomReviewer'], 403);

        $request->validate(['comment' => ['required', 'string', 'max:2000']]);
        $workflow->submitHomeroomReview($reportCard, $request->user(), $request->string('comment')->toString());

        return back();
    }

    public function approve(Request $request, CurrentTenant $currentTenant, ReportCard $reportCard, ReportCardWorkflow $workflow): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($reportCard->tenant_id === $tenant->id, 404);
        abort_unless($this->has($tenant->id, $request->user()->id, TenantMembership::ROLE_DIRECTOR), 403);

        $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);
        $workflow->approve($reportCard, $request->user(), $request->string('comment')->toString() ?: null);

        return back();
    }

    public function publish(Request $request, CurrentTenant $currentTenant, ReportCard $reportCard, ReportCardWorkflow $workflow): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($reportCard->tenant_id === $tenant->id, 404);
        abort_unless($this->hasAny($tenant->id, $request->user()->id, self::MANAGEMENT_ROLES), 403);

        $workflow->publish($reportCard, $request->user(), $this->schoolName($tenant));

        return back();
    }

    public function returnForChanges(Request $request, CurrentTenant $currentTenant, ReportCard $reportCard, ReportCardWorkflow $workflow): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($reportCard->tenant_id === $tenant->id, 404);
        abort_unless($this->mayReturn($reportCard->status, $this->staffAccess($tenant->id, $request->user(), $reportCard)), 403);

        $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'subject_ids' => ['array'],
            'subject_ids.*' => ['integer'],
        ]);

        $workflow->returnForChanges($reportCard, $request->user(), $request->string('reason')->toString(), array_map('intval', $request->array('subject_ids')));

        return back();
    }

    public function revise(Request $request, CurrentTenant $currentTenant, ReportCard $reportCard, ReportCardWorkflow $workflow): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($reportCard->tenant_id === $tenant->id, 404);
        abort_unless($this->staffAccess($tenant->id, $request->user(), $reportCard)['isSeniorReviewer'], 403);

        $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'subject_ids' => ['array'],
            'subject_ids.*' => ['integer'],
        ]);

        $workflow->revise($reportCard, $request->user(), $request->string('reason')->toString(), array_map('intval', $request->array('subject_ids')));

        return back();
    }

    /**
     * Published report cards of one student — the family-facing list.
     */
    public function forStudent(Request $request, CurrentTenant $currentTenant, Student $student): Response
    {
        $tenant = $currentTenant->get();
        abort_unless($student->tenant_id === $tenant->id, 404);
        abort_unless($this->mayViewPublished($tenant->id, $request->user(), $student), 403);

        $publications = ReportCardPublication::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('report_card_id', ReportCard::query()
                ->where('tenant_id', $tenant->id)
                ->where('student_id', $student->id)
                ->select('id'))
            ->orderByDesc('published_at')
            ->get()
            ->groupBy('report_card_id')
            ->map(fn ($group) => $group->sortByDesc('revision')->first());

        return Inertia::render('portal/report-cards/student', [
            'student' => ['id' => $student->id, 'name' => $student->fullName()],
            'publications' => $publications->map(fn (ReportCardPublication $publication) => [
                'id' => $publication->id,
                'periodName' => $publication->snapshot['periodName'] ?? '',
                'academicYearName' => $publication->snapshot['academicYearName'] ?? null,
                'revision' => $publication->revision,
                'publishedAt' => $publication->published_at->toIso8601String(),
            ])->values(),
        ]);
    }

    /**
     * The printable document for one published revision. Data comes only
     * from the frozen snapshot.
     */
    public function showPublication(Request $request, CurrentTenant $currentTenant, ReportCardPublication $publication): Response
    {
        $tenant = $currentTenant->get();
        abort_unless($publication->tenant_id === $tenant->id, 404);
        $publication->load('reportCard.student');

        abort_unless($this->mayViewPublished($tenant->id, $request->user(), $publication->reportCard->student), 403);

        $latestRevision = (int) ReportCardPublication::query()
            ->where('tenant_id', $tenant->id)
            ->where('report_card_id', $publication->report_card_id)
            ->max('revision');

        return Inertia::render('portal/report-cards/document', [
            'document' => $publication->snapshot,
            'isLatest' => $publication->revision === $latestRevision,
            'verificationUrl' => route('report-cards.verify', $publication->verification_code),
            'studentId' => $publication->reportCard->student_id,
        ]);
    }

    /**
     * @param  array{seesAll: bool, isDirector: bool, isPublisher: bool, isSeniorReviewer: bool, isHomeroomReviewer: bool, ownTeacherAssignmentIds: array<int, int>}  $access
     */
    private function mayReturn(string $status, array $access): bool
    {
        return match ($status) {
            ReportCard::STATUS_TEACHER_COMPLETE => $access['isHomeroomReviewer'],
            ReportCard::STATUS_HOMEROOM_REVIEW, ReportCard::STATUS_DIRECTOR_APPROVED => $access['isSeniorReviewer'],
            default => false,
        };
    }

    /**
     * @return array{seesAll: bool, isDirector: bool, isPublisher: bool, isSeniorReviewer: bool, isHomeroomReviewer: bool, ownTeacherAssignmentIds: array<int, int>}
     */
    private function staffAccess(int $tenantId, User $user, ReportCard $card): array
    {
        $isManagement = $this->hasAny($tenantId, $user->id, self::MANAGEMENT_ROLES);
        $isDirector = $this->has($tenantId, $user->id, TenantMembership::ROLE_DIRECTOR);
        $isAcademicManager = $this->has($tenantId, $user->id, TenantMembership::ROLE_ACADEMIC_MANAGER);
        $isTeacher = $this->has($tenantId, $user->id, TenantMembership::ROLE_TEACHER);

        $card->loadMissing('schoolClass');
        $isHomeroom = $isTeacher && $card->schoolClass->homeroom_teacher_id === $user->id;

        $ownIds = $isTeacher
            ? TeacherAssignment::query()
                ->where('tenant_id', $tenantId)
                ->where('user_id', $user->id)
                ->where('school_class_id', $card->school_class_id)
                ->pluck('id')
                ->all()
            : [];

        return [
            'seesAll' => $isManagement || $isHomeroom,
            'isDirector' => $isDirector,
            'isPublisher' => $isManagement,
            'isSeniorReviewer' => $isDirector || $isAcademicManager,
            'isHomeroomReviewer' => $isHomeroom || $isAcademicManager,
            'ownTeacherAssignmentIds' => $ownIds,
        ];
    }

    private function mayViewPublished(int $tenantId, User $user, Student $student): bool
    {
        if ($this->hasAny($tenantId, $user->id, self::MANAGEMENT_ROLES)) {
            return true;
        }

        if ($student->user_id === $user->id && $student->is_active && $this->has($tenantId, $user->id, TenantMembership::ROLE_STUDENT)) {
            return true;
        }

        if ($this->has($tenantId, $user->id, TenantMembership::ROLE_GUARDIAN)
            && GuardianLink::query()
                ->where('tenant_id', $tenantId)
                ->where('user_id', $user->id)
                ->where('student_id', $student->id)
                ->where('is_active', true)
                ->where('can_view_academic', true)
                ->exists()) {
            return true;
        }

        // The class's homeroom teacher also sees what families see.
        return $student->school_class_id !== null
            && $this->has($tenantId, $user->id, TenantMembership::ROLE_TEACHER)
            && SchoolClass::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($student->school_class_id)
                ->where('homeroom_teacher_id', $user->id)
                ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function queueRow(ReportCard $card): array
    {
        return [
            'id' => $card->id,
            'studentName' => $card->student->fullName(),
            'className' => $card->schoolClass->name,
            'periodName' => $card->period->name,
            'status' => $card->status,
            'statusLabel' => self::LABELS[$card->status] ?? $card->status,
        ];
    }

    /**
     * The caller's own teacher_assignment ids — empty unless they hold an
     * active teacher membership in this tenant.
     *
     * @return array<int, int>
     */
    private function teacherAssignmentIds(int $tenantId, int $userId): array
    {
        if (! $this->has($tenantId, $userId, TenantMembership::ROLE_TEACHER)) {
            return [];
        }

        return TeacherAssignment::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->pluck('id')
            ->all();
    }

    private function schoolName(Tenant $tenant): string
    {
        return $tenant->brandSetting !== null ? $tenant->brandSetting->display_name : $tenant->name;
    }

    private function has(int $tenantId, int $userId, string $role): bool
    {
        return TenantMembership::userHasActiveRole($tenantId, $userId, $role);
    }

    /**
     * @param  array<int, string>  $roles
     */
    private function hasAny(int $tenantId, int $userId, array $roles): bool
    {
        return TenantMembership::userHasAnyActiveRole($tenantId, $userId, $roles);
    }
}
