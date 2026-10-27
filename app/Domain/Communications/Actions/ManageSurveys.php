<?php

namespace App\Domain\Communications\Actions;

use App\Domain\Communications\Models\Survey;
use App\Domain\Communications\Models\SurveyAnswer;
use App\Domain\Communications\Models\SurveyQuestion;
use App\Domain\Communications\Models\SurveyResponse;
use App\Domain\Governance\AuditLogger;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\PortalContext;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Surveys from draft to closed, and answering them. A draft's questions
 * can change; once open they are fixed, so every answer is to the same
 * question. Each eligible person answers once — enforced by a keyed hash
 * of survey and person, which for an anonymous survey is all that is kept.
 */
class ManageSurveys
{
    public function __construct(private readonly AuditLogger $auditLogger, private readonly PortalContext $portalContext) {}

    /**
     * @param  array{title: string, description: string|null, audience: string, is_anonymous: bool, closes_on: string|null}  $details
     * @param  list<array{type: string, prompt: string, options: list<string>, is_required: bool}>  $questions
     */
    public function saveDraft(int $tenantId, ?Survey $survey, array $details, array $questions, User $actor): Survey
    {
        if ($survey !== null && $survey->status !== Survey::STATUS_DRAFT) {
            throw ValidationException::withMessages(['title' => 'გახსნილი გამოკითხვის კითხვები აღარ იცვლება.']);
        }

        return DB::transaction(function () use ($tenantId, $survey, $details, $questions, $actor) {
            $survey ??= tap(new Survey(['status' => Survey::STATUS_DRAFT, 'created_by' => $actor->id]), fn (Survey $s) => $s->tenant_id = $tenantId);
            $created = ! $survey->exists;
            $survey->fill($details)->save();
            $survey->questions()->delete();

            foreach ($questions as $position => $question) {
                $row = new SurveyQuestion([
                    'survey_id' => $survey->id,
                    'position' => $position + 1,
                    'type' => $question['type'],
                    'prompt' => $question['prompt'],
                    'options' => in_array($question['type'], ['single', 'multiple'], true) ? $question['options'] : null,
                    'is_required' => $question['is_required'],
                ]);
                $row->tenant_id = $tenantId;
                $row->save();
            }

            $this->auditLogger->record($tenantId, $created ? 'survey.created' : 'survey.updated', $survey, $actor->id, ['questions' => count($questions)]);

            return $survey;
        });
    }

    public function open(Survey $survey, User $actor): void
    {
        if ($survey->status !== Survey::STATUS_DRAFT || ! $survey->questions()->exists()) {
            throw ValidationException::withMessages(['status' => 'გახსნა შეიძლება მხოლოდ კითხვების მქონე მონახაზის.']);
        }

        $survey->update(['status' => Survey::STATUS_OPEN, 'opened_at' => now()]);
        $this->auditLogger->record($survey->tenant_id, 'survey.opened', $survey, $actor->id, ['audience' => $survey->audience]);
    }

    public function close(Survey $survey, User $actor): void
    {
        if ($survey->status !== Survey::STATUS_OPEN) {
            throw ValidationException::withMessages(['status' => 'გამოკითხვა გახსნილი არ არის.']);
        }

        $survey->update(['status' => Survey::STATUS_CLOSED, 'closed_at' => now()]);
        $this->auditLogger->record($survey->tenant_id, 'survey.closed', $survey, $actor->id, ['responses' => $survey->responses()->count()]);
    }

    /**
     * The role a person answers in, or null when the survey is not for them.
     */
    public function respondentRole(Survey $survey, int $userId): ?string
    {
        $roles = array_values(array_intersect($this->portalContext->activeRoles($survey->tenant_id, $userId), Survey::AUDIENCES[$survey->audience] ?? []));

        return $roles[0] ?? null;
    }

    public function hasAnswered(Survey $survey, int $userId): bool
    {
        return SurveyResponse::query()->where('tenant_id', $survey->tenant_id)->where('survey_id', $survey->id)
            ->where('respondent_hash', $this->respondentHash($survey, $userId))->exists();
    }

    /**
     * @param  array<int|string, mixed>  $answers  question id => value
     */
    public function respond(Survey $survey, User $user, array $answers, Carbon $today): void
    {
        $role = $this->respondentRole($survey, $user->id);

        if ($role === null) {
            abort(403);
        }

        if (! $survey->isAcceptingAnswers($today)) {
            throw ValidationException::withMessages(['answers' => 'გამოკითხვა დასრულებულია.']);
        }

        $values = [];
        $errors = [];

        foreach ($survey->questions as $question) {
            $value = $this->clean($question, $answers[$question->id] ?? null);

            if ($value === null && $question->is_required) {
                $errors["answers.{$question->id}"] = 'ამ კითხვას პასუხი სჭირდება.';
            } elseif ($value !== null) {
                $values[$question->id] = $value;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        try {
            DB::transaction(function () use ($survey, $user, $role, $values) {
                $response = new SurveyResponse([
                    'survey_id' => $survey->id,
                    'respondent_hash' => $this->respondentHash($survey, $user->id),
                    'user_id' => $survey->is_anonymous ? null : $user->id,
                    'role' => $role,
                ]);
                $response->tenant_id = $survey->tenant_id;
                $response->save();

                foreach ($values as $questionId => $value) {
                    $answer = new SurveyAnswer(['survey_response_id' => $response->id, 'survey_question_id' => $questionId, 'value' => $value]);
                    $answer->tenant_id = $survey->tenant_id;
                    $answer->save();
                }
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['answers' => 'ამ გამოკითხვას უკვე უპასუხეთ.']);
        }
    }

    /**
     * A valid answer in its stored shape, or null when there is none.
     *
     * @return list<int>|int|string|null
     */
    private function clean(SurveyQuestion $question, mixed $value): array|int|string|null
    {
        $count = count($question->options ?? []);

        return match ($question->type) {
            'single' => is_numeric($value) && (int) $value >= 0 && (int) $value < $count ? (int) $value : null,
            'multiple' => is_array($value)
                ? (($picked = array_values(array_unique(array_map('intval', array_filter($value, fn ($v) => is_numeric($v) && (int) $v >= 0 && (int) $v < $count))))) === [] ? null : $picked)
                : null,
            'scale' => is_numeric($value) && (int) $value >= 1 && (int) $value <= 5 ? (int) $value : null,
            default => is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 2000) : null,
        };
    }

    private function respondentHash(Survey $survey, int $userId): string
    {
        return hash_hmac('sha256', "survey:{$survey->id}:user:{$userId}", (string) config('app.key'));
    }

    /**
     * Results: per question the counts per option, the scale's spread and
     * mean, or the text answers (without names when anonymous).
     *
     * @return array{responses: int, byRole: array<string, int>, questions: list<array{id: int, type: string, prompt: string, answered: int, options: list<array{label: string, count: int}>, mean: float|null, texts: list<array{text: string, name: string|null}>}>}
     */
    public function results(Survey $survey): array
    {
        $survey->loadMissing('questions');
        $responses = SurveyResponse::query()->where('tenant_id', $survey->tenant_id)->where('survey_id', $survey->id)->with('answers')->get();
        $names = $survey->is_anonymous ? collect() : User::query()->whereIn('id', $responses->pluck('user_id')->filter())->pluck('name', 'id');
        $questions = [];

        foreach ($survey->questions as $question) {
            $labels = $question->type === 'scale' ? ['1', '2', '3', '4', '5'] : ($question->options ?? []);
            $counts = array_fill(0, count($labels), 0);
            $answered = 0;
            $scaleSum = 0;
            $texts = [];

            foreach ($responses as $response) {
                foreach ($response->answers->where('survey_question_id', $question->id) as $answer) {
                    $answered++;
                    $value = $answer->value;

                    if ($question->type === 'text') {
                        $texts[] = ['text' => is_string($value) ? $value : '', 'name' => $response->user_id === null ? null : ($names[$response->user_id] ?? null)];

                        continue;
                    }

                    foreach (is_array($value) ? $value : [$value] as $picked) {
                        $index = $question->type === 'scale' ? (int) $picked - 1 : (int) $picked;

                        if (isset($counts[$index])) {
                            $counts[$index]++;
                        }
                    }

                    $scaleSum += $question->type === 'scale' && is_int($value) ? $value : 0;
                }
            }

            $questions[] = [
                'id' => $question->id,
                'type' => $question->type,
                'prompt' => $question->prompt,
                'answered' => $answered,
                'options' => $question->type === 'text' ? [] : array_map(fn (string $label, int $count) => ['label' => $label, 'count' => $count], $labels, $counts),
                'mean' => $question->type === 'scale' && $answered > 0 ? round($scaleSum / $answered, 2) : null,
                'texts' => $texts,
            ];
        }

        return [
            'responses' => $responses->count(),
            'byRole' => $responses->countBy('role')->all(),
            'questions' => $questions,
        ];
    }

    /**
     * The people a survey is for, counted once each.
     */
    public function audienceSize(Survey $survey): int
    {
        return TenantMembership::query()->where('tenant_id', $survey->tenant_id)->where('is_active', true)
            ->whereIn('role', Survey::AUDIENCES[$survey->audience] ?? [])->distinct()->count('user_id');
    }
}
