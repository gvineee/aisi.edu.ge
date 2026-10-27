<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\TeacherAssignment;
use App\Domain\Governance\AuditLogger;
use App\Domain\Learning\Actions\CurriculumProgress;
use App\Domain\Learning\CurriculumAccess;
use App\Domain\Learning\Models\CurriculumPlan;
use App\Domain\Learning\Models\CurriculumTopic;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Timetable\Models\Lesson;
use App\Domain\Timetable\Models\Subject;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Curriculum plans („სასწავლო გეგმები“): for every class/subject pair the
 * school teaches, the ordered topics of the year and how far the class
 * register says the class has got. Teachers draft their own pairs' plans,
 * management approves; CurriculumAccess holds the rules.
 */
class CurriculumController extends Controller
{
    public function index(Request $request, CurrentTenant $currentTenant, CurriculumAccess $access, CurriculumProgress $progress): Response
    {
        $tenant = $currentTenant->get();
        $userId = $request->user()->id;
        abort_unless($access->canView($tenant->id, $userId), 403);
        $isManager = $access->isManager($tenant->id, $userId);

        $years = AcademicYear::query()->where('tenant_id', $tenant->id)->orderByDesc('starts_on')->get();
        $year = $years->firstWhere('id', $request->integer('year')) ?? $years->firstWhere('is_current', true) ?? $years->first();
        $classes = $year === null ? collect() : SchoolClass::query()->where('tenant_id', $tenant->id)->where('academic_year_id', $year->id)->get()->keyBy('id');
        $classIds = $classes->keys()->all();

        // Every pair the school teaches: workloads, timetabled lessons, plans.
        $assignments = TeacherAssignment::query()->where('tenant_id', $tenant->id)->whereIn('school_class_id', $classIds)->whereNotNull('subject_id')
            ->with('teacher:id,name', 'staffMember:id,full_name')->get();
        $lessonPairs = Lesson::query()->where('tenant_id', $tenant->id)->whereIn('school_class_id', $classIds)
            ->select('school_class_id', 'subject_id', 'teacher_id')->distinct()->get();
        $plans = CurriculumPlan::query()->where('tenant_id', $tenant->id)->whereIn('school_class_id', $classIds)->with('topics')->get();

        $pairs = [];
        $mine = [];

        foreach ($assignments as $ta) {
            $key = "{$ta->school_class_id}-{$ta->subject_id}";
            $pairs[$key] ??= ['classId' => $ta->school_class_id, 'subjectId' => (int) $ta->subject_id, 'teachers' => []];
            $pairs[$key]['teachers'][] = $ta->teacher->name ?? $ta->staffMember->full_name ?? null;
            $mine[$key] = ($mine[$key] ?? false) || $ta->user_id === $userId;
        }

        foreach ($lessonPairs as $lesson) {
            $key = "{$lesson->school_class_id}-{$lesson->subject_id}";
            $pairs[$key] ??= ['classId' => $lesson->school_class_id, 'subjectId' => $lesson->subject_id, 'teachers' => []];
            $mine[$key] = ($mine[$key] ?? false) || $lesson->teacher_id === $userId;
        }

        foreach ($plans as $plan) {
            $pairs["{$plan->school_class_id}-{$plan->subject_id}"] ??= ['classId' => $plan->school_class_id, 'subjectId' => $plan->subject_id, 'teachers' => []];
        }

        $subjects = Subject::query()->where('tenant_id', $tenant->id)->whereIn('id', array_column($pairs, 'subjectId'))->pluck('name', 'id');
        $plansByPair = $plans->keyBy(fn (CurriculumPlan $plan) => "{$plan->school_class_id}-{$plan->subject_id}");
        $stats = $progress->handle($tenant->id, $plans, Carbon::now($tenant->timezone));

        $rows = collect($pairs)
            ->filter(fn (array $pair, string $key) => $isManager || ($mine[$key] ?? false))
            ->map(function (array $pair, string $key) use ($classes, $subjects, $plansByPair, $stats, $mine) {
                $plan = $plansByPair->get($key);

                return [
                    'key' => $key,
                    'classId' => $pair['classId'],
                    'className' => $classes->get($pair['classId'])->name ?? '',
                    'subjectId' => $pair['subjectId'],
                    'subjectName' => $subjects->get($pair['subjectId']) ?? '',
                    'teachers' => array_values(array_unique(array_filter($pair['teachers']))),
                    'isMine' => $mine[$key] ?? false,
                    'plan' => $plan === null ? null : ['id' => $plan->id, 'status' => $plan->status] + array_diff_key($stats[$plan->id], ['byTopic' => true]),
                ];
            })
            ->sortBy([fn (array $a, array $b) => strnatcmp($a['className'], $b['className']), fn (array $a, array $b) => strcmp($a['subjectName'], $b['subjectName'])])
            ->values();

        return Inertia::render('portal/curriculum/index', [
            'years' => $years->map(fn (AcademicYear $y) => ['id' => $y->id, 'name' => $y->name])->values(),
            'yearId' => $year?->id,
            'isManager' => $isManager,
            'rows' => $rows,
        ]);
    }

    public function show(Request $request, CurrentTenant $currentTenant, CurriculumPlan $curriculumPlan, CurriculumAccess $access, CurriculumProgress $progress): Response
    {
        $tenant = $currentTenant->get();
        abort_unless($curriculumPlan->tenant_id === $tenant->id, 404);
        $userId = $request->user()->id;
        abort_unless($access->canView($tenant->id, $userId), 403);

        $curriculumPlan->load('topics', 'schoolClass', 'subject');
        $stats = $progress->handle($tenant->id, collect([$curriculumPlan]), Carbon::now($tenant->timezone))[$curriculumPlan->id];

        // Other classes' plans in the same subject, to copy topics from.
        $sources = CurriculumPlan::query()->where('tenant_id', $tenant->id)->where('subject_id', $curriculumPlan->subject_id)
            ->whereKeyNot($curriculumPlan->id)->whereHas('topics')->with('schoolClass.academicYear')->get()
            ->map(fn (CurriculumPlan $plan) => ['id' => $plan->id, 'label' => ($plan->schoolClass->name ?? '').' · '.($plan->schoolClass->academicYear->name ?? '')])
            ->values();

        return Inertia::render('portal/curriculum/show', [
            'plan' => [
                'id' => $curriculumPlan->id,
                'status' => $curriculumPlan->status,
                'notes' => $curriculumPlan->notes,
                'className' => $curriculumPlan->schoolClass->name,
                'subjectName' => $curriculumPlan->subject->name,
                'approvedAt' => $curriculumPlan->approved_at?->toIso8601String(),
            ],
            'topics' => $curriculumPlan->topics->map(fn (CurriculumTopic $topic) => [
                'id' => $topic->id,
                'title' => $topic->title,
                'plannedHours' => $topic->planned_hours,
                'plannedFrom' => $topic->planned_from?->toDateString(),
                'plannedTo' => $topic->planned_to?->toDateString(),
                'lessons' => $stats['byTopic'][$topic->id]['lessons'] ?? 0,
                'firstOn' => $stats['byTopic'][$topic->id]['firstOn'] ?? null,
                'lastOn' => $stats['byTopic'][$topic->id]['lastOn'] ?? null,
            ])->values(),
            'stats' => array_diff_key($stats, ['byTopic' => true]),
            'today' => Carbon::now($tenant->timezone)->toDateString(),
            'canEdit' => $access->canEdit($curriculumPlan, $userId),
            'isManager' => $access->isManager($tenant->id, $userId),
            'sources' => $sources,
        ]);
    }

    public function store(Request $request, CurrentTenant $currentTenant, CurriculumAccess $access, AuditLogger $auditLogger): RedirectResponse
    {
        $tenantId = $currentTenant->get()->id;
        $userId = $request->user()->id;
        $data = $request->validate([
            'school_class_id' => ['required', 'integer', Rule::exists('school_classes', 'id')->where('tenant_id', $tenantId)],
            'subject_id' => ['required', 'integer', Rule::exists('subjects', 'id')->where('tenant_id', $tenantId)],
        ]);
        abort_unless($access->isManager($tenantId, $userId) || $access->teaches($tenantId, $userId, (int) $data['school_class_id'], (int) $data['subject_id']), 403);

        $class = SchoolClass::query()->where('tenant_id', $tenantId)->findOrFail((int) $data['school_class_id']);
        $plan = CurriculumPlan::query()->where('tenant_id', $tenantId)->where('school_class_id', $class->id)->where('subject_id', (int) $data['subject_id'])->first();

        if ($plan === null) {
            $plan = new CurriculumPlan(['academic_year_id' => $class->academic_year_id, 'school_class_id' => $class->id, 'subject_id' => (int) $data['subject_id'], 'status' => CurriculumPlan::STATUS_DRAFT, 'created_by' => $userId]);
            $plan->tenant_id = $tenantId;
            $plan->save();
            $auditLogger->record($tenantId, 'curriculum.plan_created', $plan, $userId);
        }

        return redirect()->route('curriculum.show', $plan);
    }

    /**
     * Adds topics: one per line, optionally "title | lessons".
     */
    public function storeTopics(Request $request, CurrentTenant $currentTenant, CurriculumPlan $curriculumPlan, CurriculumAccess $access, AuditLogger $auditLogger): RedirectResponse
    {
        $tenantId = $this->authorizeEdit($request, $currentTenant, $curriculumPlan, $access);
        $data = $request->validate([
            'lines' => ['nullable', 'string', 'max:20000', 'required_without:copy_from'],
            'copy_from' => ['nullable', 'integer'],
        ]);

        $new = [];

        if (! empty($data['copy_from'])) {
            $source = CurriculumPlan::query()->where('tenant_id', $tenantId)->where('subject_id', $curriculumPlan->subject_id)->findOrFail((int) $data['copy_from']);
            $new = $source->topics->map(fn (CurriculumTopic $topic) => ['title' => $topic->title, 'planned_hours' => $topic->planned_hours])->all();
        } else {
            foreach (preg_split('/\R/u', (string) $data['lines']) ?: [] as $line) {
                [$title, $hours] = array_pad(array_map('trim', explode('|', $line, 2)), 2, '');

                if ($title === '') {
                    continue;
                }

                $new[] = ['title' => mb_substr($title, 0, 200), 'planned_hours' => max(1, min(200, (int) ($hours ?: 1)))];
            }
        }

        if ($new === [] || count($new) > 300) {
            throw ValidationException::withMessages(['lines' => 'ჩაწერეთ თემები (თითო ხაზზე ერთი, მაქსიმუმ 300).']);
        }

        DB::transaction(function () use ($curriculumPlan, $tenantId, $new) {
            $position = (int) $curriculumPlan->topics()->max('position');

            foreach ($new as $values) {
                $topic = new CurriculumTopic($values + ['curriculum_plan_id' => $curriculumPlan->id, 'position' => ++$position]);
                $topic->tenant_id = $tenantId;
                $topic->save();
            }
        });
        $auditLogger->record($tenantId, 'curriculum.topics_added', $curriculumPlan, $request->user()->id, ['count' => count($new), 'copied_from' => $data['copy_from'] ?? null]);

        return back()->with('toast', ['type' => 'success', 'message' => count($new).' თემა დაემატა.']);
    }

    public function updateTopic(Request $request, CurrentTenant $currentTenant, CurriculumPlan $curriculumPlan, CurriculumTopic $curriculumTopic, CurriculumAccess $access): RedirectResponse
    {
        $this->authorizeEdit($request, $currentTenant, $curriculumPlan, $access);
        abort_unless($curriculumTopic->curriculum_plan_id === $curriculumPlan->id, 404);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'planned_hours' => ['required', 'integer', 'min:1', 'max:200'],
            'planned_from' => ['nullable', 'date_format:Y-m-d'],
            'planned_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:planned_from'],
        ]);
        $curriculumTopic->update(['title' => trim($data['title'])] + $data);

        return back()->with('toast', ['type' => 'success', 'message' => 'თემა შენახულია.']);
    }

    public function moveTopic(Request $request, CurrentTenant $currentTenant, CurriculumPlan $curriculumPlan, CurriculumTopic $curriculumTopic, CurriculumAccess $access): RedirectResponse
    {
        $this->authorizeEdit($request, $currentTenant, $curriculumPlan, $access);
        abort_unless($curriculumTopic->curriculum_plan_id === $curriculumPlan->id, 404);
        $direction = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]])['direction'];

        DB::transaction(function () use ($curriculumPlan, $curriculumTopic, $direction) {
            $ids = array_map('intval', $curriculumPlan->topics()->pluck('id')->all());
            $at = array_search($curriculumTopic->id, $ids, true);

            if (! is_int($at)) {
                return;
            }

            $to = $direction === 'up' ? $at - 1 : $at + 1;

            if (! isset($ids[$to])) {
                return;
            }

            [$ids[$at], $ids[$to]] = [$ids[$to], $ids[$at]];

            foreach ($ids as $position => $id) {
                CurriculumTopic::query()->whereKey($id)->update(['position' => $position + 1]);
            }
        });

        return back();
    }

    public function destroyTopic(Request $request, CurrentTenant $currentTenant, CurriculumPlan $curriculumPlan, CurriculumTopic $curriculumTopic, CurriculumAccess $access, AuditLogger $auditLogger): RedirectResponse
    {
        $tenantId = $this->authorizeEdit($request, $currentTenant, $curriculumPlan, $access);
        abort_unless($curriculumTopic->curriculum_plan_id === $curriculumPlan->id, 404);
        $auditLogger->record($tenantId, 'curriculum.topic_removed', $curriculumPlan, $request->user()->id, ['title' => $curriculumTopic->title]);
        $curriculumTopic->delete(); // journal entries keep their text, lose the link

        return back()->with('toast', ['type' => 'success', 'message' => 'თემა წაიშალა.']);
    }

    public function setStatus(Request $request, CurrentTenant $currentTenant, CurriculumPlan $curriculumPlan, CurriculumAccess $access, AuditLogger $auditLogger): RedirectResponse
    {
        $tenantId = $currentTenant->get()->id;
        abort_unless($curriculumPlan->tenant_id === $tenantId, 404);
        abort_unless($access->isManager($tenantId, $request->user()->id), 403);
        $status = $request->validate(['status' => ['required', Rule::in([CurriculumPlan::STATUS_DRAFT, CurriculumPlan::STATUS_APPROVED])]])['status'];

        if ($status === CurriculumPlan::STATUS_APPROVED && ! $curriculumPlan->topics()->exists()) {
            throw ValidationException::withMessages(['status' => 'ცარიელ გეგმას ვერ დაამტკიცებთ.']);
        }

        $curriculumPlan->update($status === CurriculumPlan::STATUS_APPROVED
            ? ['status' => $status, 'approved_by' => $request->user()->id, 'approved_at' => now()]
            : ['status' => $status, 'approved_by' => null, 'approved_at' => null]);
        $auditLogger->record($tenantId, $status === CurriculumPlan::STATUS_APPROVED ? 'curriculum.plan_approved' : 'curriculum.plan_reopened', $curriculumPlan, $request->user()->id);

        return back()->with('toast', ['type' => 'success', 'message' => $status === CurriculumPlan::STATUS_APPROVED ? 'გეგმა დამტკიცდა.' : 'გეგმა ისევ მონახაზია.']);
    }

    private function authorizeEdit(Request $request, CurrentTenant $currentTenant, CurriculumPlan $plan, CurriculumAccess $access): int
    {
        $tenantId = $currentTenant->get()->id;
        abort_unless($plan->tenant_id === $tenantId, 404);
        abort_unless($access->canEdit($plan, $request->user()->id), 403);

        return $tenantId;
    }
}
