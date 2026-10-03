<?php

namespace Tests\Feature\Timetable;

use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Timetable\Models\Lesson;
use App\Domain\Timetable\Models\LessonHandoff;
use App\Domain\Timetable\Models\StaffAbsence;
use App\Domain\Timetable\Models\SubstitutionAssignment;
use App\Domain\Timetable\Models\SubstitutionRequest;
use App\Mail\SubstituteAssignedMail;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * CLAUDE-PLATFORM-MODULES Â§9 beyond the base workflow: teacher coverage
 * requests with locked approval, computed candidate ranking, the database
 * guard against a second active cover of one lesson occurrence, a
 * post-commit notification, and lesson handoffs bound to the assignment.
 */
class SubstitutionCoverageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function fixtures(): array
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;
        $today = Carbon::now($tenant->timezone);
        $year = $tenant->academicYears()->create(['name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-15', 'is_current' => true]);
        $class = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => 'A']);
        $otherClass = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => 'B']);
        $subject = $tenant->subjects()->create(['name' => 'Math']);

        $member = function (string $role) use ($tenant): User {
            $user = User::factory()->create();
            TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role, 'is_active' => true]);

            return $user;
        };
        $admin = $member(TenantMembership::ROLE_ADMIN);
        $absent = $member(TenantMembership::ROLE_TEACHER);
        $busy = $member(TenantMembership::ROLE_TEACHER);
        $qualified = $member(TenantMembership::ROLE_TEACHER);
        $plain = $member(TenantMembership::ROLE_TEACHER);

        $makeLesson = function (int $teacherId, int $classId, string $start, string $end) use ($tenant, $year, $subject, $today): Lesson {
            $lesson = new Lesson([
                'academic_year_id' => $year->id, 'school_class_id' => $classId, 'subject_id' => $subject->id, 'teacher_id' => $teacherId,
                'day_of_week' => $today->dayOfWeekIso, 'starts_at' => $start, 'ends_at' => $end, 'status' => Lesson::STATUS_PUBLISHED,
            ]);
            $lesson->tenant_id = $tenant->id;
            $lesson->save();

            return $lesson;
        };
        $lesson = $makeLesson($absent->id, $class->id, '09:00:00', '09:45:00');
        $makeLesson($busy->id, $otherClass->id, '09:15:00', '10:00:00');
        $tenant->teacherAssignments()->create(['user_id' => $qualified->id, 'school_class_id' => $otherClass->id, 'subject' => 'Math']);

        return compact('tenant', 'today', 'admin', 'absent', 'busy', 'qualified', 'plain', 'lesson');
    }

    public function test_teacher_request_approval_candidates_and_race_guard(): void
    {
        Mail::fake();
        $f = $this->fixtures();
        $today = $f['today']->toDateString();

        // Teacher asks; a different teacher cannot withdraw it.
        $this->actingAs($f['absent'])->post('/portal/my-coverage/requests', ['starts_on' => $today, 'ends_on' => $today, 'reason' => 'áƒ¢áƒ áƒ”áƒœáƒ˜áƒœáƒ’áƒ˜'])->assertRedirect();
        $request = SubstitutionRequest::query()->firstOrFail();
        $this->actingAs($f['plain'])->post("/portal/my-coverage/requests/{$request->id}/withdraw")->assertForbidden();
        $this->actingAs($f['absent'])->post('/portal/substitutions/requests/'.$request->id.'/decide', ['approve' => true])->assertForbidden();

        // Coordinator approves once; a second decision is rejected.
        $this->actingAs($f['admin'])->post("/portal/substitutions/requests/{$request->id}/decide", ['approve' => true])->assertRedirect();
        $this->assertSame(SubstitutionRequest::STATUS_APPROVED, $request->fresh()->status);
        $this->assertSame(1, StaffAbsence::query()->count());
        $this->actingAs($f['admin'])->post("/portal/substitutions/requests/{$request->id}/decide", ['approve' => false])->assertSessionHasErrors('request');
        $this->assertSame(1, StaffAbsence::query()->count());

        // Candidates: free teachers only, the subject-qualified one first.
        $key = $f['lesson']->id.':'.$today;
        $this->actingAs($f['admin'])->get('/portal/substitutions')->assertInertia(fn (Assert $page) => $page
            ->has("candidates.{$key}", 2)
            ->where("candidates.{$key}.0.id", $f['qualified']->id)
            ->where("candidates.{$key}.0.qualified", true)
            ->where("candidates.{$key}.1.id", $f['plain']->id)
            ->has('requests', 1));

        // Assign; the substitute is e-mailed after commit.
        $absence = StaffAbsence::query()->firstOrFail();
        $this->actingAs($f['admin'])->post('/portal/substitutions/assign', [
            'lesson_id' => $f['lesson']->id, 'substitute_teacher_id' => $f['qualified']->id, 'date' => $today, 'absence_id' => $absence->id,
        ])->assertRedirect();
        Mail::assertQueued(SubstituteAssignedMail::class, fn (SubstituteAssignedMail $mail) => $mail->hasTo($f['qualified']->email));

        // A second assignment of the same occurrence is refused, and the
        // database itself rejects a second active row.
        $this->actingAs($f['admin'])->post('/portal/substitutions/assign', [
            'lesson_id' => $f['lesson']->id, 'substitute_teacher_id' => $f['plain']->id, 'date' => $today,
        ])->assertSessionHasErrors('lesson_id');

        $duplicate = new SubstitutionAssignment([
            'lesson_id' => $f['lesson']->id, 'absent_teacher_id' => $f['absent']->id, 'substitute_teacher_id' => $f['plain']->id,
            'date' => $today, 'status' => SubstitutionAssignment::STATUS_ASSIGNED,
        ]);
        $duplicate->tenant_id = $f['tenant']->id;
        $duplicate->active_key = SubstitutionAssignment::activeKeyFor($f['lesson']->id, $today);

        try {
            $duplicate->save();
            $this->fail('A second active cover of one occurrence must be rejected.');
        } catch (UniqueConstraintViolationException) {
            $this->addToAssertionCount(1);
        }

        // Cancelling frees the occurrence for a new assignment.
        $assignment = SubstitutionAssignment::query()->firstOrFail();
        $this->actingAs($f['admin'])->post("/portal/substitutions/{$assignment->id}/cancel")->assertRedirect();
        $this->assertNull($assignment->fresh()->active_key);
        $this->actingAs($f['admin'])->post('/portal/substitutions/assign', [
            'lesson_id' => $f['lesson']->id, 'substitute_teacher_id' => $f['plain']->id, 'date' => $today,
        ])->assertSessionHasNoErrors();
    }

    public function test_handoff_is_written_by_absent_teacher_and_read_only_by_the_substitute(): void
    {
        Mail::fake();
        $f = $this->fixtures();
        $today = $f['today']->toDateString();
        $this->actingAs($f['admin'])->post('/portal/substitutions/assign', [
            'lesson_id' => $f['lesson']->id, 'substitute_teacher_id' => $f['plain']->id, 'date' => $today,
        ])->assertSessionHasNoErrors();
        $assignment = SubstitutionAssignment::query()->firstOrFail();

        // The absent teacher's day centre asks for a plan until one exists.
        $feed = $this->actingAs($f['absent'])->get('/portal/today')->viewData('page')['props']['actionItems'];
        $this->assertContains('handoff', array_column($feed, 'type'));

        $this->actingAs($f['busy'])->post("/portal/substitutions/{$assignment->id}/handoff", ['plan' => 'x'])->assertForbidden();
        $this->actingAs($f['plain'])->post("/portal/substitutions/{$assignment->id}/handoff", ['plan' => 'x'])->assertForbidden();
        $this->actingAs($f['absent'])->post("/portal/substitutions/{$assignment->id}/handoff", ['plan' => 'áƒ’áƒ•. 42, áƒ¡áƒáƒ•áƒáƒ áƒ¯áƒ˜áƒ¨áƒ 3', 'materials' => 'áƒ¡áƒáƒ®áƒ”áƒšáƒ›áƒ«áƒ¦áƒ•áƒáƒœáƒ”áƒšáƒ'])->assertRedirect();
        $this->assertSame(1, LessonHandoff::query()->count());

        $feed = $this->actingAs($f['absent'])->get('/portal/today')->viewData('page')['props']['actionItems'];
        $this->assertNotContains('handoff', array_column($feed, 'type'));

        // The substitute reads it on today's lesson page and on their coverage page.
        $this->actingAs($f['plain'])->get("/portal/teacher/lessons/{$f['lesson']->id}")
            ->assertInertia(fn (Assert $page) => $page->where('isSubstitute', true)->where('handoff.plan', 'áƒ’áƒ•. 42, áƒ¡áƒáƒ•áƒáƒ áƒ¯áƒ˜áƒ¨áƒ 3'));
        $this->actingAs($f['plain'])->get('/portal/my-coverage')
            ->assertInertia(fn (Assert $page) => $page->has('iCover', 1)->where('iCover.0.handoff.materials', 'áƒ¡áƒáƒ®áƒ”áƒšáƒ›áƒ«áƒ¦áƒ•áƒáƒœáƒ”áƒšáƒ'));
        $this->actingAs($f['busy'])->get('/portal/my-coverage')->assertInertia(fn (Assert $page) => $page->has('iCover', 0)->has('coveredForMe', 0));
        $this->actingAs($f['busy'])->get("/portal/teacher/lessons/{$f['lesson']->id}")->assertForbidden();

        // After cancellation the substitute loses access and the plan is frozen.
        $this->actingAs($f['admin'])->post("/portal/substitutions/{$assignment->id}/cancel");
        $this->actingAs($f['plain'])->get("/portal/teacher/lessons/{$f['lesson']->id}")->assertForbidden();
        $this->actingAs($f['absent'])->post("/portal/substitutions/{$assignment->id}/handoff", ['plan' => 'new'])->assertSessionHasErrors('plan');
    }

    public function test_other_tenant_cannot_touch_requests_or_handoffs(): void
    {
        $f = $this->fixtures();
        $this->actingAs($f['absent'])->post('/portal/my-coverage/requests', ['starts_on' => $f['today']->toDateString(), 'ends_on' => $f['today']->toDateString()]);
        $request = SubstitutionRequest::query()->firstOrFail();

        $other = Tenant::create(['name' => 'Other', 'slug' => 'other', 'timezone' => 'Asia/Tbilisi', 'locale' => 'ka']);
        TenantDomain::create(['tenant_id' => $other->id, 'domain' => 'other-school.test', 'is_primary' => true]);
        $otherAdmin = User::factory()->create();
        TenantMembership::create(['tenant_id' => $other->id, 'user_id' => $otherAdmin->id, 'role' => TenantMembership::ROLE_ADMIN, 'is_active' => true]);

        $this->actingAs($otherAdmin)->post("http://other-school.test/portal/substitutions/requests/{$request->id}/decide", ['approve' => true])->assertNotFound();
        $this->assertSame(SubstitutionRequest::STATUS_PENDING, $request->fresh()->status);
    }
}
