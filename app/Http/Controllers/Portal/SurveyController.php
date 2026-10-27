<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Communications\Actions\ManageSurveys;
use App\Domain\Communications\Models\Survey;
use App\Domain\Communications\Models\SurveyQuestion;
use App\Domain\Governance\AuditLogger;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * „გამოკითხვები“: management builds, opens and closes surveys and reads the
 * results; guardians, staff and pupils answer the open ones meant for them.
 */
class SurveyController extends Controller
{
    /** @var list<string> */
    public const MANAGE_ROLES = [
        TenantMembership::ROLE_ADMIN,
        TenantMembership::ROLE_DIRECTOR,
        TenantMembership::ROLE_ACADEMIC_MANAGER,
    ];

    public function __construct(private readonly ManageSurveys $surveys) {}

    public function index(Request $request, CurrentTenant $currentTenant): Response
    {
        $tenant = $currentTenant->get();
        $userId = $request->user()->id;
        $isManager = $this->isManager($tenant->id, $userId);
        $today = Carbon::now($tenant->timezone);

        $surveys = Survey::query()->where('tenant_id', $tenant->id)
            ->when(! $isManager, fn ($query) => $query->where('status', Survey::STATUS_OPEN))
            ->withCount('responses')->latest('id')->get()
            ->filter(fn (Survey $survey) => $isManager || $this->surveys->respondentRole($survey, $userId) !== null);

        return Inertia::render('portal/surveys/index', [
            'isManager' => $isManager,
            'surveys' => $surveys->map(fn (Survey $survey) => [
                'id' => $survey->id,
                'title' => $survey->title,
                'audience' => $survey->audience,
                'status' => $survey->status,
                'isAnonymous' => $survey->is_anonymous,
                'closesOn' => $survey->closes_on?->toDateString(),
                'accepting' => $survey->isAcceptingAnswers($today),
                'responses' => $isManager ? $survey->responses_count : null,
                'audienceSize' => $isManager ? $this->surveys->audienceSize($survey) : null,
                'answered' => $this->surveys->hasAnswered($survey, $userId),
            ])->values(),
        ]);
    }

    public function create(Request $request, CurrentTenant $currentTenant): Response
    {
        $this->authorizeManage($request, $currentTenant->get()->id);

        return Inertia::render('portal/surveys/edit', ['survey' => null]);
    }

    public function edit(Request $request, CurrentTenant $currentTenant, Survey $survey): Response|RedirectResponse
    {
        $this->authorizeSurvey($request, $currentTenant, $survey);

        if ($survey->status !== Survey::STATUS_DRAFT) {
            return redirect()->route('surveys.show', $survey);
        }

        return Inertia::render('portal/surveys/edit', ['survey' => $this->surveyPayload($survey)]);
    }

    public function store(Request $request, CurrentTenant $currentTenant): RedirectResponse
    {
        $tenantId = $currentTenant->get()->id;
        $this->authorizeManage($request, $tenantId);
        [$details, $questions] = $this->validated($request);
        $survey = $this->surveys->saveDraft($tenantId, null, $details, $questions, $request->user());

        return redirect()->route('surveys.show', $survey)->with('toast', ['type' => 'success', 'message' => 'მონახაზი შენახულია.']);
    }

    public function update(Request $request, CurrentTenant $currentTenant, Survey $survey): RedirectResponse
    {
        $tenantId = $this->authorizeSurvey($request, $currentTenant, $survey);
        [$details, $questions] = $this->validated($request);
        $this->surveys->saveDraft($tenantId, $survey, $details, $questions, $request->user());

        return redirect()->route('surveys.show', $survey)->with('toast', ['type' => 'success', 'message' => 'მონახაზი შენახულია.']);
    }

    public function setStatus(Request $request, CurrentTenant $currentTenant, Survey $survey): RedirectResponse
    {
        $this->authorizeSurvey($request, $currentTenant, $survey);
        $status = $request->validate(['status' => ['required', Rule::in([Survey::STATUS_OPEN, Survey::STATUS_CLOSED])]])['status'];
        $status === Survey::STATUS_OPEN ? $this->surveys->open($survey, $request->user()) : $this->surveys->close($survey, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => $status === Survey::STATUS_OPEN ? 'გამოკითხვა გაიხსნა.' : 'გამოკითხვა დაიხურა.']);
    }

    public function destroy(Request $request, CurrentTenant $currentTenant, Survey $survey, AuditLogger $auditLogger): RedirectResponse
    {
        $tenantId = $this->authorizeSurvey($request, $currentTenant, $survey);
        abort_unless($survey->status === Survey::STATUS_DRAFT, 422);
        $auditLogger->record($tenantId, 'survey.deleted', $survey, $request->user()->id, ['title' => $survey->title]);
        $survey->delete();

        return redirect()->route('surveys.index')->with('toast', ['type' => 'success', 'message' => 'მონახაზი წაიშალა.']);
    }

    public function show(Request $request, CurrentTenant $currentTenant, Survey $survey): Response
    {
        $tenant = $currentTenant->get();
        abort_unless($survey->tenant_id === $tenant->id, 404);
        $userId = $request->user()->id;
        $isManager = $this->isManager($tenant->id, $userId);
        $canAnswer = $survey->status === Survey::STATUS_OPEN && $this->surveys->respondentRole($survey, $userId) !== null;
        abort_unless($isManager || $canAnswer, 403);

        return Inertia::render('portal/surveys/show', [
            'survey' => $this->surveyPayload($survey),
            'isManager' => $isManager,
            'canAnswer' => $canAnswer && $survey->isAcceptingAnswers(Carbon::now($tenant->timezone)),
            'answered' => $this->surveys->hasAnswered($survey, $userId),
            'results' => $isManager ? $this->surveys->results($survey) : null,
            'audienceSize' => $isManager ? $this->surveys->audienceSize($survey) : null,
        ]);
    }

    public function respond(Request $request, CurrentTenant $currentTenant, Survey $survey): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($survey->tenant_id === $tenant->id, 404);
        $data = $request->validate(['answers' => ['array']]);
        $this->surveys->respond($survey->load('questions'), $request->user(), $data['answers'] ?? [], Carbon::now($tenant->timezone));

        return back()->with('toast', ['type' => 'success', 'message' => 'გმადლობთ, პასუხი მიღებულია.']);
    }

    public function export(Request $request, CurrentTenant $currentTenant, Survey $survey, AuditLogger $auditLogger): StreamedResponse
    {
        $tenantId = $this->authorizeSurvey($request, $currentTenant, $survey);
        $results = $this->surveys->results($survey);
        $auditLogger->record($tenantId, 'survey.exported', $survey, $request->user()->id, ['responses' => $results['responses']]);

        return response()->streamDownload(function () use ($results) {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF"); // Excel reads UTF-8 Georgian only with a BOM
            fputcsv($out, ['კითხვა', 'პასუხი', 'რაოდენობა']);

            foreach ($results['questions'] as $question) {
                foreach ($question['options'] as $option) {
                    fputcsv($out, [$question['prompt'], $option['label'], $option['count']]);
                }

                foreach ($question['texts'] as $text) {
                    fputcsv($out, [$question['prompt'], $text['text'], '']);
                }
            }

            fclose($out);
        }, "survey-{$survey->id}.csv", ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    /**
     * @return array{0: array{title: string, description: string|null, audience: string, is_anonymous: bool, closes_on: string|null}, 1: list<array{type: string, prompt: string, options: list<string>, is_required: bool}>}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:3000'],
            'audience' => ['required', Rule::in(array_keys(Survey::AUDIENCES))],
            'is_anonymous' => ['boolean'],
            'closes_on' => ['nullable', 'date_format:Y-m-d'],
            'questions' => ['required', 'array', 'min:1', 'max:50'],
            'questions.*.type' => ['required', Rule::in(SurveyQuestion::TYPES)],
            'questions.*.prompt' => ['required', 'string', 'max:500'],
            'questions.*.options' => ['array', 'max:20'],
            'questions.*.options.*' => ['nullable', 'string', 'max:200'],
            'questions.*.is_required' => ['boolean'],
        ], ['questions.required' => 'დაამატეთ ერთი კითხვა მაინც.']);

        $questions = [];

        foreach ($data['questions'] as $index => $question) {
            $options = array_values(array_filter(array_map(fn ($option) => trim((string) $option), $question['options'] ?? []), fn (string $option) => $option !== ''));

            if (in_array($question['type'], ['single', 'multiple'], true) && count($options) < 2) {
                throw ValidationException::withMessages(["questions.{$index}.options" => 'არჩევანის კითხვას ორი პასუხი მაინც სჭირდება.']);
            }

            $questions[] = ['type' => $question['type'], 'prompt' => trim($question['prompt']), 'options' => $options, 'is_required' => (bool) ($question['is_required'] ?? true)];
        }

        return [[
            'title' => trim($data['title']),
            'description' => isset($data['description']) ? trim($data['description']) : null,
            'audience' => $data['audience'],
            'is_anonymous' => (bool) ($data['is_anonymous'] ?? true),
            'closes_on' => $data['closes_on'] ?? null,
        ], $questions];
    }

    /**
     * @return array<string, mixed>
     */
    private function surveyPayload(Survey $survey): array
    {
        return [
            'id' => $survey->id,
            'title' => $survey->title,
            'description' => $survey->description,
            'audience' => $survey->audience,
            'isAnonymous' => $survey->is_anonymous,
            'status' => $survey->status,
            'closesOn' => $survey->closes_on?->toDateString(),
            'questions' => $survey->questions->map(fn (SurveyQuestion $question) => [
                'id' => $question->id,
                'type' => $question->type,
                'prompt' => $question->prompt,
                'options' => $question->options ?? [],
                'isRequired' => $question->is_required,
            ])->values(),
        ];
    }

    private function isManager(int $tenantId, int $userId): bool
    {
        return TenantMembership::userHasAnyActiveRole($tenantId, $userId, self::MANAGE_ROLES);
    }

    private function authorizeManage(Request $request, int $tenantId): void
    {
        abort_unless($this->isManager($tenantId, $request->user()->id), 403);
    }

    private function authorizeSurvey(Request $request, CurrentTenant $currentTenant, Survey $survey): int
    {
        $tenantId = $currentTenant->get()->id;
        abort_unless($survey->tenant_id === $tenantId, 404);
        $this->authorizeManage($request, $tenantId);

        return $tenantId;
    }
}
