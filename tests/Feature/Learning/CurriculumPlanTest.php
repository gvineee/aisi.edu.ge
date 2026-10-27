<?php

namespace Tests\Feature\Learning;

use App\Domain\Learning\Models\CurriculumPlan;
use App\Domain\Learning\Models\CurriculumTopic;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Timetable\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Curriculum plans: the teacher of a class/subject drafts the topics,
 * management approves, and the class register's topic choice is the
 * progress.
 */
class CurriculumPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_teacher_plans_records_progress_and_management_approves(): void
    {
        $this->travelTo(Carbon::parse('2026-10-26 10:00', 'Asia/Tbilisi')); // a Monday
        $tenant = TenantDomain::query()->where('domain', 'localhost')->firstOrFail()->tenant;
        $year = $tenant->academicYears()->create(['name' => '2026-2027', 'starts_on' => '2026-09-03', 'ends_on' => '2027-07-01', 'is_current' => true]);
        $a = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => '5ა']);
        $b = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => '5ბ']);
        $math = $tenant->subjects()->create(['name' => 'მათემატიკა']);

        [$manager, $teacher, $other] = array_map(function (string $role) use ($tenant) {
            $user = User::factory()->create();
            TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role, 'is_active' => true]);

            return $user;
        }, [TenantMembership::ROLE_ACADEMIC_MANAGER, TenantMembership::ROLE_TEACHER, TenantMembership::ROLE_TEACHER]);

        $lesson = fn ($class, User $by) => tap(new Lesson([
            'academic_year_id' => $year->id, 'school_class_id' => $class->id, 'subject_id' => $math->id, 'teacher_id' => $by->id,
            'day_of_week' => 1, 'starts_at' => '09:30:00', 'ends_at' => '10:15:00', 'status' => Lesson::STATUS_PUBLISHED,
        ]), function (Lesson $l) use ($tenant) {
            $l->tenant_id = $tenant->id;
            $l->save();
        });
        $mine = $lesson($a, $teacher);
        $lesson($b, $other);

        // The list: management sees both pairs, a teacher only their own.
        $this->actingAs($manager)->get('/portal/curriculum')->assertOk()->assertInertia(fn ($page) => $page->component('portal/curriculum/index')->has('rows', 2)->where('rows.0.plan', null));
        $this->actingAs($teacher)->get('/portal/curriculum')->assertInertia(fn ($page) => $page->has('rows', 1)->where('rows.0.className', '5ა')->where('rows.0.isMine', true));

        // Only the pair's teacher (or management) starts its plan.
        $this->actingAs($other)->post('/portal/curriculum', ['school_class_id' => $a->id, 'subject_id' => $math->id])->assertForbidden();
        $this->actingAs($teacher)->post('/portal/curriculum', ['school_class_id' => $a->id, 'subject_id' => $math->id])->assertRedirect();
        $plan = CurriculumPlan::query()->where('school_class_id', $a->id)->sole();
        $this->assertSame([CurriculumPlan::STATUS_DRAFT, $year->id], [$plan->status, $plan->academic_year_id]);

        $this->actingAs($teacher)->post("/portal/curriculum/{$plan->id}/topics", ['lines' => "ნატურალური რიცხვები | 4\n\nწილადები|6\nგეომეტრია"])->assertSessionHasNoErrors();
        $topics = $plan->topics()->get();
        $this->assertSame([['ნატურალური რიცხვები', 4], ['წილადები', 6], ['გეომეტრია', 1]], $topics->map(fn (CurriculumTopic $t) => [$t->title, $t->planned_hours])->all());
        $this->actingAs($other)->post("/portal/curriculum/{$plan->id}/topics", ['lines' => 'x'])->assertForbidden();

        // The first topic was due last week and is not covered yet.
        $this->actingAs($teacher)->put("/portal/curriculum/{$plan->id}/topics/{$topics[0]->id}", ['title' => 'ნატურალური რიცხვები', 'planned_hours' => 4, 'planned_from' => '2026-09-07', 'planned_to' => '2026-10-20'])->assertSessionHasNoErrors();
        $this->actingAs($teacher)->post("/portal/curriculum/{$plan->id}/topics/{$topics[2]->id}/move", ['direction' => 'up'])->assertRedirect();
        $this->assertSame(['ნატურალური რიცხვები', 'გეომეტრია', 'წილადები'], $plan->topics()->pluck('title')->all());
        $this->actingAs($other)->get("/portal/curriculum/{$plan->id}")->assertOk()->assertInertia(fn ($page) => $page->where('canEdit', false)->where('stats.overdue', 1)->where('stats.covered', 0));

        // The register names the topic taught; a topic of another plan is refused.
        $this->actingAs($teacher)->get("/portal/teacher/lessons/{$mine->id}")->assertInertia(fn ($page) => $page->has('planTopics', 3)->where('planTopics.0.covered', false));
        $this->actingAs($teacher)->post("/portal/teacher/lessons/{$mine->id}/record", ['occurs_on' => '2026-10-26', 'topic' => 'შეკრება', 'curriculum_topic_id' => $topics[0]->id])->assertSessionHasNoErrors();
        $foreignPlan = new CurriculumPlan(['academic_year_id' => $year->id, 'school_class_id' => $b->id, 'subject_id' => $math->id, 'status' => CurriculumPlan::STATUS_DRAFT]);
        $foreignPlan->tenant_id = $tenant->id;
        $foreignPlan->save();
        $foreignTopic = new CurriculumTopic(['curriculum_plan_id' => $foreignPlan->id, 'position' => 1, 'title' => 'სხვა', 'planned_hours' => 1]);
        $foreignTopic->tenant_id = $tenant->id;
        $foreignTopic->save();
        $this->actingAs($teacher)->post("/portal/teacher/lessons/{$mine->id}/record", ['occurs_on' => '2026-10-19', 'curriculum_topic_id' => $foreignTopic->id])->assertSessionHasErrors('curriculum_topic_id');

        $this->actingAs($manager)->get("/portal/curriculum/{$plan->id}")->assertInertia(fn ($page) => $page
            ->where('stats.covered', 1)->where('stats.overdue', 0)->where('stats.journalLessons', 1)->where('stats.linkedLessons', 1)
            ->where('topics.0.lessons', 1)->where('topics.0.firstOn', '2026-10-26')->where('canEdit', true));
        $this->actingAs($teacher)->get("/portal/teacher/lessons/{$mine->id}")->assertInertia(fn ($page) => $page->where('todayTopicId', $topics[0]->id)->where('recentTopics.0.topic', 'ნატურალური რიცხვები — შეკრება'));

        // Copying another class's topics.
        $this->actingAs($manager)->post("/portal/curriculum/{$foreignPlan->id}/topics", ['copy_from' => $plan->id])->assertSessionHasNoErrors();
        $this->assertSame(['სხვა', 'ნატურალური რიცხვები', 'გეომეტრია', 'წილადები'], $foreignPlan->topics()->pluck('title')->all());

        // Approval is management's, and closes the plan to the teacher.
        $this->actingAs($teacher)->post("/portal/curriculum/{$plan->id}/status", ['status' => 'approved'])->assertForbidden();
        $this->actingAs($manager)->post("/portal/curriculum/{$plan->id}/status", ['status' => 'approved'])->assertSessionHasNoErrors();
        $this->assertSame(CurriculumPlan::STATUS_APPROVED, $plan->fresh()->status);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->id, 'action' => 'curriculum.plan_approved']);
        $this->actingAs($teacher)->delete("/portal/curriculum/{$plan->id}/topics/{$topics[1]->id}")->assertForbidden();
        $this->actingAs($manager)->delete("/portal/curriculum/{$plan->id}/topics/{$topics[1]->id}")->assertSessionHasNoErrors();
        $this->assertSame(2, $plan->topics()->count());

        $empty = new CurriculumPlan(['academic_year_id' => $year->id, 'school_class_id' => $a->id, 'subject_id' => $tenant->subjects()->create(['name' => 'ხელოვნება'])->id, 'status' => CurriculumPlan::STATUS_DRAFT]);
        $empty->tenant_id = $tenant->id;
        $empty->save();
        $this->actingAs($manager)->post("/portal/curriculum/{$empty->id}/status", ['status' => 'approved'])->assertSessionHasErrors('status');
    }
}
