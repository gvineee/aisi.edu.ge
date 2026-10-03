<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Timetable\Actions\ManageSubstitutionRequests;
use App\Domain\Timetable\Actions\SaveLessonHandoff;
use App\Domain\Timetable\Models\SubstitutionAssignment;
use App\Domain\Timetable\Models\SubstitutionRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Teacher side of CLAUDE-PLATFORM-MODULES §9 ("ჩემი ჩანაცვლებები"): ask for
 * cover, write the handoff plan for own lessons someone else covers, and
 * read the plan for lessons I cover. A handoff is visible to the
 * substitute only through an active assignment and only until the end of
 * the covered day; the roster stays limited to the covered day itself
 * (TeacherWorkspaceController).
 */
class CoverageController extends Controller
{
    /**
     * @var array<int, string>
     */
    private const COORDINATOR_ROLES = [
        TenantMembership::ROLE_ACADEMIC_MANAGER,
        TenantMembership::ROLE_DIRECTOR,
        TenantMembership::ROLE_ADMIN,
    ];

    public function index(Request $request, CurrentTenant $currentTenant): Response
    {
        $tenant = $currentTenant->get();
        $user = $request->user();
        abort_unless(TenantMembership::userHasActiveRole($tenant->id, $user->id, TenantMembership::ROLE_TEACHER), 403);
        $today = Carbon::now($tenant->timezone)->toDateString();

        $row = fn (SubstitutionAssignment $a, bool $mine) => [
            'id' => $a->id,
            'date' => $a->date->toDateString(),
            'subject' => $a->lesson->subject->name ?? null,
            'className' => $a->lesson->schoolClass->name ?? null,
            'startsAt' => substr($a->lesson->starts_at, 0, 5),
            'endsAt' => substr($a->lesson->ends_at, 0, 5),
            'otherTeacherName' => $mine ? $a->substituteTeacher->name : $a->absentTeacher->name,
            'handoff' => $a->handoff === null ? null : [
                'plan' => $a->handoff->plan,
                'materials' => $a->handoff->materials,
                'notes' => $a->handoff->notes,
            ],
        ];
        $active = fn () => SubstitutionAssignment::query()->where('tenant_id', $tenant->id)->where('status', SubstitutionAssignment::STATUS_ASSIGNED)
            ->whereDate('date', '>=', $today)->with('lesson.subject', 'lesson.schoolClass', 'absentTeacher', 'substituteTeacher', 'handoff')->orderBy('date');

        return Inertia::render('portal/coverage/index', [
            'requests' => SubstitutionRequest::query()->where('tenant_id', $tenant->id)->where('requester_id', $user->id)->latest('id')->limit(20)->get()
                ->map(fn (SubstitutionRequest $r) => [
                    'id' => $r->id,
                    'startsOn' => $r->starts_on->toDateString(),
                    'endsOn' => $r->ends_on->toDateString(),
                    'reason' => $r->reason,
                    'status' => $r->status,
                    'decisionNote' => $r->decision_note,
                ])->values(),
            'coveredForMe' => $active()->where('absent_teacher_id', $user->id)->get()->map(fn (SubstitutionAssignment $a) => $row($a, true))->values(),
            'iCover' => $active()->where('substitute_teacher_id', $user->id)->get()->map(fn (SubstitutionAssignment $a) => $row($a, false))->values(),
        ]);
    }

    public function storeRequest(Request $request, CurrentTenant $currentTenant, ManageSubstitutionRequests $action): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $user = $request->user();
        abort_unless(TenantMembership::userHasActiveRole($tenant->id, $user->id, TenantMembership::ROLE_TEACHER), 403);

        $data = $request->validate([
            'starts_on' => ['required', 'date', 'after_or_equal:'.Carbon::now($tenant->timezone)->toDateString()],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $action->submit($tenant->id, $user, Carbon::parse($data['starts_on']), Carbon::parse($data['ends_on']), $data['reason'] ?? null);

        return back()->with('toast', ['type' => 'success', 'message' => 'მოთხოვნა გაიგზავნა.']);
    }

    public function withdrawRequest(Request $request, CurrentTenant $currentTenant, SubstitutionRequest $substitutionRequest, ManageSubstitutionRequests $action): RedirectResponse
    {
        abort_unless($substitutionRequest->tenant_id === $currentTenant->get()->id, 404);
        $action->withdraw($substitutionRequest, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'მოთხოვნა გაუქმდა.']);
    }

    public function saveHandoff(Request $request, CurrentTenant $currentTenant, SubstitutionAssignment $substitutionAssignment, SaveLessonHandoff $action): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $user = $request->user();
        abort_unless($substitutionAssignment->tenant_id === $tenant->id, 404);

        $isAbsentTeacher = $substitutionAssignment->absent_teacher_id === $user->id
            && TenantMembership::userHasActiveRole($tenant->id, $user->id, TenantMembership::ROLE_TEACHER);
        abort_unless($isAbsentTeacher || TenantMembership::userHasAnyActiveRole($tenant->id, $user->id, self::COORDINATOR_ROLES), 403);

        $data = $request->validate([
            'plan' => ['required', 'string', 'max:5000'],
            'materials' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $action->handle($substitutionAssignment, $user, $data['plan'], $data['materials'] ?? null, $data['notes'] ?? null);

        return back()->with('toast', ['type' => 'success', 'message' => 'გეგმა შენახულია.']);
    }
}
