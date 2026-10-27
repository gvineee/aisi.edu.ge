<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Models\Survey;
use App\Domain\Communications\Models\SurveyResponse;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Surveys: management drafts and opens one; only its audience answers,
 * once each; an anonymous survey keeps no respondent; results add up.
 */
class SurveyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guardian_survey_from_draft_to_results(): void
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->firstOrFail()->tenant;
        [$manager, $parentA, $parentB, $teacher] = array_map(function (string $role) use ($tenant) {
            $user = User::factory()->create();
            TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role, 'is_active' => true]);

            return $user;
        }, [TenantMembership::ROLE_ACADEMIC_MANAGER, TenantMembership::ROLE_GUARDIAN, TenantMembership::ROLE_GUARDIAN, TenantMembership::ROLE_TEACHER]);

        $draft = [
            'title' => 'კვების კმაყოფილება', 'audience' => 'guardians', 'is_anonymous' => true,
            'questions' => [
                ['type' => 'single', 'prompt' => 'როგორ შეაფასებთ?', 'options' => ['კარგი', 'საშუალო', ' ', 'ცუდი'], 'is_required' => true],
                ['type' => 'multiple', 'prompt' => 'რა მოგწონთ?', 'options' => ['სადილი', 'ხილი', 'წვენი'], 'is_required' => false],
                ['type' => 'scale', 'prompt' => 'რამდენად?', 'options' => [], 'is_required' => true],
                ['type' => 'text', 'prompt' => 'შენიშვნა', 'options' => [], 'is_required' => false],
            ],
        ];

        $this->actingAs($teacher)->post('/portal/surveys', $draft)->assertForbidden();
        $this->actingAs($manager)->post('/portal/surveys', ['questions' => [['type' => 'single', 'prompt' => 'x', 'options' => ['a']]]] + $draft)->assertSessionHasErrors('questions.0.options');
        $this->actingAs($manager)->post('/portal/surveys', $draft)->assertRedirect();
        $survey = Survey::query()->sole();
        $questions = $survey->questions()->get();
        $this->assertSame(['კარგი', 'საშუალო', 'ცუდი'], $questions[0]->options, 'blank options are dropped');

        // A draft is invisible to its audience.
        $this->actingAs($parentA)->get("/portal/surveys/{$survey->id}")->assertForbidden();
        $this->actingAs($manager)->post("/portal/surveys/{$survey->id}/status", ['status' => 'open'])->assertSessionHasNoErrors();
        $this->actingAs($manager)->put("/portal/surveys/{$survey->id}", $draft)->assertSessionHasErrors('title');

        $this->actingAs($parentA)->get('/portal/surveys')->assertInertia(fn ($page) => $page->has('surveys', 1)->where('surveys.0.answered', false));
        $this->actingAs($teacher)->get('/portal/surveys')->assertInertia(fn ($page) => $page->has('surveys', 0));
        $this->actingAs($teacher)->post("/portal/surveys/{$survey->id}/responses", ['answers' => [$questions[0]->id => 0, $questions[2]->id => 3]])->assertForbidden();

        $answer = fn (User $user, array $answers) => $this->actingAs($user)->post("/portal/surveys/{$survey->id}/responses", ['answers' => $answers]);
        $answer($parentA, [$questions[2]->id => 4])->assertSessionHasErrors("answers.{$questions[0]->id}");
        $answer($parentA, [$questions[0]->id => 9, $questions[2]->id => 4])->assertSessionHasErrors("answers.{$questions[0]->id}");
        $answer($parentA, [$questions[0]->id => 0, $questions[1]->id => [0, 2, 2], $questions[2]->id => 4, $questions[3]->id => ' მეტი ბოსტნეული '])->assertSessionHasNoErrors();
        $answer($parentA, [$questions[0]->id => 1, $questions[2]->id => 1])->assertSessionHasErrors('answers');
        $answer($parentB, [$questions[0]->id => 0, $questions[2]->id => 2])->assertSessionHasNoErrors();

        $this->assertSame(2, SurveyResponse::query()->count());
        $this->assertSame(0, SurveyResponse::query()->whereNotNull('user_id')->count(), 'anonymous: no user ids kept');
        $this->actingAs($parentA)->get("/portal/surveys/{$survey->id}")->assertInertia(fn ($page) => $page->where('answered', true)->where('results', null));

        $this->actingAs($manager)->get("/portal/surveys/{$survey->id}")->assertInertia(fn ($page) => $page
            ->where('results.responses', 2)->where('audienceSize', 2)
            ->where('results.questions.0.options.0', ['label' => 'კარგი', 'count' => 2])
            ->where('results.questions.1.options', [['label' => 'სადილი', 'count' => 1], ['label' => 'ხილი', 'count' => 0], ['label' => 'წვენი', 'count' => 1]])
            ->where('results.questions.2.mean', 3)
            ->where('results.questions.3.texts', [['text' => 'მეტი ბოსტნეული', 'name' => null]]));

        $this->actingAs($manager)->get("/portal/surveys/{$survey->id}/export")->assertOk();
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->id, 'action' => 'survey.exported']);

        $this->actingAs($manager)->post("/portal/surveys/{$survey->id}/status", ['status' => 'closed'])->assertSessionHasNoErrors();
        $this->actingAs($parentB)->get('/portal/surveys')->assertInertia(fn ($page) => $page->has('surveys', 0));
    }
}
