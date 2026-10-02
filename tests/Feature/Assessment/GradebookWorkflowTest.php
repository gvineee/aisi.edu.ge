<?php

namespace Tests\Feature\Assessment;

use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Academics\Models\Student;
use App\Domain\Assessment\Models\Assessment;
use App\Domain\Assessment\Models\AssessmentPeriod;
use App\Domain\Assessment\Models\AssessmentResult;
use App\Domain\Assessment\Models\AssessmentResultRevision;
use App\Domain\Governance\Models\AuditEvent;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Covers CLAUDE-PLATFORM-MODULES §6/§17 for the gradebook: unpublished
 * grades stay hidden from families, a teacher only grades their own
 * class/subject, score changes carry reason/version/audit, a closed period
 * rejects edits, stale forms are rejected, guardian revocation and
 * inactive membership cut access, and tenant isolation.
 */
class GradebookWorkflowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{tenant: Tenant, admin: User, teacher: User, otherTeacher: User, teacherAssignmentId: int, otherTeacherAssignmentId: int, studentUser: User, student: Student, otherStudent: Student, guardian: User, period: AssessmentPeriod}
     */
    private function fixtures(): array
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;

        $year = $tenant->academicYears()->create([
            'name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-15', 'is_current' => true,
        ]);
        $class = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => 'VI']);
        $otherClass = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => 'VII']);

        $admin = User::factory()->create();
        $teacher = User::factory()->create();
        $otherTeacher = User::factory()->create();
        $studentUser = User::factory()->create();
        $guardian = User::factory()->create();

        foreach ([
            [$admin, TenantMembership::ROLE_ACADEMIC_MANAGER],
            [$teacher, TenantMembership::ROLE_TEACHER],
            [$otherTeacher, TenantMembership::ROLE_TEACHER],
            [$studentUser, TenantMembership::ROLE_STUDENT],
            [$guardian, TenantMembership::ROLE_GUARDIAN],
        ] as [$user, $role]) {
            TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role, 'is_active' => true]);
        }

        $teacherAssignment = $tenant->teacherAssignments()->create(['user_id' => $teacher->id, 'school_class_id' => $class->id, 'subject' => 'მათემატიკა']);
        $otherTeacherAssignment = $tenant->teacherAssignments()->create(['user_id' => $otherTeacher->id, 'school_class_id' => $otherClass->id, 'subject' => 'ისტორია']);

        $student = $tenant->students()->create([
            'school_class_id' => $class->id, 'user_id' => $studentUser->id,
            'first_name' => 'ნიკა', 'last_name' => 'დ.', 'is_active' => true,
        ]);
        $otherStudent = $tenant->students()->create([
            'school_class_id' => $otherClass->id, 'first_name' => 'გიორგი', 'last_name' => 'მ.', 'is_active' => true,
        ]);

        $link = new GuardianLink([
            'user_id' => $guardian->id, 'student_id' => $student->id,
            'can_view_academic' => true, 'can_view_financial' => false, 'can_pickup' => true,
            'can_receive_notifications' => true, 'is_active' => true,
        ]);
        $link->tenant_id = $tenant->id;
        $link->save();

        $this->actingAs($admin)->post('/portal/assessment-settings/periods', [
            'academic_year_id' => $year->id, 'name' => 'I სემესტრი', 'starts_on' => '2026-09-01', 'ends_on' => '2027-01-31',
        ])->assertRedirect();

        return [
            'tenant' => $tenant,
            'admin' => $admin,
            'teacher' => $teacher,
            'otherTeacher' => $otherTeacher,
            'teacherAssignmentId' => $teacherAssignment->id,
            'otherTeacherAssignmentId' => $otherTeacherAssignment->id,
            'studentUser' => $studentUser,
            'student' => $student,
            'otherStudent' => $otherStudent,
            'guardian' => $guardian,
            'period' => AssessmentPeriod::query()->firstOrFail(),
        ];
    }

    /**
     * @param  array<string, mixed>  $f
     */
    private function createAssessment(array $f, string $maxScore = '10'): Assessment
    {
        $this->actingAs($f['teacher'])->post('/portal/gradebook/assessments', [
            'teacher_assignment_id' => $f['teacherAssignmentId'],
            'assessment_period_id' => $f['period']->id,
            'title' => 'წილადები',
            'assessed_on' => '2026-10-01',
            'max_score' => $maxScore,
        ])->assertRedirect();

        return Assessment::query()->latest('id')->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function scoreStudent(User $actor, Assessment $assessment, Student $student, string|float $score, array $extra = []): TestResponse
    {
        return $this->actingAs($actor)->post("/portal/gradebook/assessments/{$assessment->id}/results", array_merge([
            'results' => [array_merge(['student_id' => $student->id, 'raw_score' => $score], $extra['row'] ?? [])],
        ], $extra['body'] ?? []));
    }

    public function test_full_flow_scores_are_normalized_graded_and_published_to_family(): void
    {
        $f = $this->fixtures();

        $this->actingAs($f['admin'])->post('/portal/assessment-settings/scales', [
            'name' => 'სატესტო სკალა',
            'bands' => [
                ['min_percent' => 0, 'label' => 'F'],
                ['min_percent' => 50, 'label' => 'C'],
                ['min_percent' => 90, 'label' => 'A'],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $assessment = $this->createAssessment($f);
        $this->assertSame(Assessment::STATUS_DRAFT, $assessment->status);
        $this->assertNotNull($assessment->grade_scale_id);

        $this->scoreStudent($f['teacher'], $assessment, $f['student'], '7.5')->assertSessionHasNoErrors();

        $result = AssessmentResult::query()->firstOrFail();
        $this->assertSame('7.50', $result->raw_score);
        $this->assertSame('75.00', $result->normalized_percent);
        $this->assertSame('C', $result->final_grade);
        $this->assertSame(1, $result->version);
        $this->assertSame(1, AssessmentResultRevision::query()->count());

        // Draft: family sees nothing yet (the "unpublished grade" case).
        foreach ([$f['guardian'], $f['studentUser']] as $viewer) {
            $this->actingAs($viewer)->get("/portal/students/{$f['student']->id}/progress")
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('portal/progress/show')->where('subjects', []));
        }

        $this->actingAs($f['teacher'])->post("/portal/gradebook/assessments/{$assessment->id}/publish")->assertRedirect();
        $this->assertSame(Assessment::STATUS_PUBLISHED, $assessment->fresh()->status);

        $this->actingAs($f['guardian'])->get("/portal/students/{$f['student']->id}/progress")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('subjects.0.subject', 'მათემატიკა')
                ->where('subjects.0.assessments.0.rawScore', '7.50')
                ->where('subjects.0.assessments.0.finalGrade', 'C'));

        $this->actingAs($f['studentUser'])->get('/portal/my-progress')
            ->assertRedirect(route('progress.show', $f['student']));

        $this->assertTrue(AuditEvent::query()->where('action', 'assessment.published')->exists());
    }

    public function test_no_grade_scale_means_no_invented_final_grade(): void
    {
        $f = $this->fixtures();
        $assessment = $this->createAssessment($f);

        $this->scoreStudent($f['teacher'], $assessment, $f['student'], 9)->assertSessionHasNoErrors();

        $result = AssessmentResult::query()->firstOrFail();
        $this->assertSame('90.00', $result->normalized_percent);
        $this->assertNull($result->final_grade);
    }

    public function test_changing_a_saved_score_requires_reason_and_bumps_version_with_audit(): void
    {
        $f = $this->fixtures();
        $assessment = $this->createAssessment($f);
        $this->scoreStudent($f['teacher'], $assessment, $f['student'], 6);

        $this->scoreStudent($f['teacher'], $assessment, $f['student'], 8, ['row' => ['expected_version' => 1]])
            ->assertSessionHasErrors('reason');
        $this->assertSame('6.00', AssessmentResult::query()->firstOrFail()->raw_score);

        $this->scoreStudent($f['teacher'], $assessment, $f['student'], 8, [
            'row' => ['expected_version' => 1],
            'body' => ['reason' => 'ნაშრომი ხელახლა შემოწმდა'],
        ])->assertSessionHasNoErrors();

        $result = AssessmentResult::query()->firstOrFail();
        $this->assertSame('8.00', $result->raw_score);
        $this->assertSame(2, $result->version);
        $this->assertSame(
            'ნაშრომი ხელახლა შემოწმდა',
            AssessmentResultRevision::query()->where('version', 2)->value('reason'),
        );

        $audit = AuditEvent::query()->where('action', 'assessment_result.changed')->firstOrFail();
        $this->assertSame('6.00', $audit->meta['from_score']);
        $this->assertSame('8.00', $audit->meta['to_score']);
    }

    public function test_stale_form_is_rejected_instead_of_overwriting(): void
    {
        $f = $this->fixtures();
        $assessment = $this->createAssessment($f);
        $this->scoreStudent($f['teacher'], $assessment, $f['student'], 6);
        $this->scoreStudent($f['teacher'], $assessment, $f['student'], 7, [
            'row' => ['expected_version' => 1], 'body' => ['reason' => 'პირველი შესწორება'],
        ])->assertSessionHasNoErrors();

        // A second tab still holding version 1 tries to save.
        $this->scoreStudent($f['teacher'], $assessment, $f['student'], 9, [
            'row' => ['expected_version' => 1], 'body' => ['reason' => 'მეორე ჩანართი'],
        ])->assertSessionHasErrors('results.0.raw_score');

        $this->assertSame('7.00', AssessmentResult::query()->firstOrFail()->raw_score);
    }

    public function test_score_bounds_and_roster_are_enforced(): void
    {
        $f = $this->fixtures();
        $assessment = $this->createAssessment($f, '10');

        $this->scoreStudent($f['teacher'], $assessment, $f['student'], 11)->assertSessionHasErrors('results.0.raw_score');
        $this->scoreStudent($f['teacher'], $assessment, $f['otherStudent'], 5)->assertSessionHasErrors('results.0.student_id');

        $this->assertSame(0, AssessmentResult::query()->count());
    }

    public function test_closed_period_rejects_edits_and_reopen_needs_reason(): void
    {
        $f = $this->fixtures();
        $assessment = $this->createAssessment($f);
        $this->scoreStudent($f['teacher'], $assessment, $f['student'], 5);

        // A teacher cannot close the period themselves.
        $this->actingAs($f['teacher'])->post("/portal/assessment-settings/periods/{$f['period']->id}/close")->assertForbidden();

        $this->actingAs($f['admin'])->post("/portal/assessment-settings/periods/{$f['period']->id}/close")->assertRedirect();
        $this->assertTrue($f['period']->fresh()->isClosed());

        $this->scoreStudent($f['teacher'], $assessment, $f['student'], 9, [
            'row' => ['expected_version' => 1], 'body' => ['reason' => 'გვიანი შესწორება'],
        ])->assertSessionHasErrors('period');
        $this->assertSame('5.00', AssessmentResult::query()->firstOrFail()->raw_score);

        $this->actingAs($f['teacher'])->post('/portal/gradebook/assessments', [
            'teacher_assignment_id' => $f['teacherAssignmentId'],
            'assessment_period_id' => $f['period']->id,
            'title' => 'ახალი', 'assessed_on' => '2026-10-02', 'max_score' => 10,
        ])->assertSessionHasErrors('assessment_period_id');

        $this->actingAs($f['admin'])->post("/portal/assessment-settings/periods/{$f['period']->id}/reopen", ['reason' => ''])
            ->assertSessionHasErrors('reason');
        $this->assertTrue($f['period']->fresh()->isClosed());

        $this->actingAs($f['admin'])->post("/portal/assessment-settings/periods/{$f['period']->id}/reopen", ['reason' => 'დირექტორის გადაწყვეტილებით'])
            ->assertRedirect();
        $this->assertFalse($f['period']->fresh()->isClosed());
        $this->assertSame(
            'დირექტორის გადაწყვეტილებით',
            AuditEvent::query()->where('action', 'assessment_period.reopened')->firstOrFail()->meta['reason'],
        );

        $this->scoreStudent($f['teacher'], $assessment, $f['student'], 9, [
            'row' => ['expected_version' => 1], 'body' => ['reason' => 'გვიანი შესწორება'],
        ])->assertSessionHasNoErrors();
    }

    public function test_unassigned_teacher_cannot_touch_another_teachers_assessment(): void
    {
        $f = $this->fixtures();
        $assessment = $this->createAssessment($f);

        $this->actingAs($f['otherTeacher'])->get("/portal/gradebook/assessments/{$assessment->id}")->assertForbidden();
        $this->scoreStudent($f['otherTeacher'], $assessment, $f['student'], 3)->assertForbidden();
        $this->actingAs($f['otherTeacher'])->post("/portal/gradebook/assessments/{$assessment->id}/publish")->assertForbidden();

        // Nor create an assessment on a teacher_assignment that is not theirs.
        $this->actingAs($f['otherTeacher'])->post('/portal/gradebook/assessments', [
            'teacher_assignment_id' => $f['teacherAssignmentId'],
            'assessment_period_id' => $f['period']->id,
            'title' => 'x', 'assessed_on' => '2026-10-02', 'max_score' => 10,
        ])->assertForbidden();

        // Nor read the progress of a student outside their classes.
        $this->actingAs($f['otherTeacher'])->get("/portal/students/{$f['student']->id}/progress")->assertForbidden();

        $this->assertSame(0, AssessmentResult::query()->count());
        $this->assertSame(Assessment::STATUS_DRAFT, $assessment->fresh()->status);
    }

    public function test_revoked_guardian_and_inactive_membership_lose_access(): void
    {
        $f = $this->fixtures();
        $url = "/portal/students/{$f['student']->id}/progress";

        $this->actingAs($f['guardian'])->get($url)->assertOk();

        GuardianLink::query()->where('user_id', $f['guardian']->id)->update(['can_view_academic' => false]);
        $this->actingAs($f['guardian'])->get($url)->assertForbidden();

        GuardianLink::query()->where('user_id', $f['guardian']->id)->update(['can_view_academic' => true, 'is_active' => false]);
        $this->actingAs($f['guardian'])->get($url)->assertForbidden();

        $this->actingAs($f['teacher'])->get($url)->assertOk();
        TenantMembership::query()->where('user_id', $f['teacher']->id)->update(['is_active' => false]);
        $this->actingAs($f['teacher'])->get($url)->assertForbidden();
        $this->actingAs($f['teacher'])->get('/portal/gradebook')->assertForbidden();
    }

    public function test_new_scale_version_does_not_regrade_existing_assessments(): void
    {
        $f = $this->fixtures();

        $this->actingAs($f['admin'])->post('/portal/assessment-settings/scales', [
            'name' => 'v1', 'bands' => [['min_percent' => 0, 'label' => 'ჩაჭრა'], ['min_percent' => 60, 'label' => 'ჩაბარება']],
        ])->assertSessionHasNoErrors();

        $first = $this->createAssessment($f);
        $this->scoreStudent($f['teacher'], $first, $f['student'], 7)->assertSessionHasNoErrors();

        $this->actingAs($f['admin'])->post('/portal/assessment-settings/scales', [
            'name' => 'v2', 'bands' => [['min_percent' => 0, 'label' => 'ჩაჭრა'], ['min_percent' => 80, 'label' => 'ჩაბარება']],
        ])->assertSessionHasNoErrors();

        $second = $this->createAssessment($f);
        $this->scoreStudent($f['teacher'], $second, $f['student'], 7)->assertSessionHasNoErrors();

        $this->assertSame('ჩაბარება', AssessmentResult::query()->where('assessment_id', $first->id)->value('final_grade'));
        $this->assertSame('ჩაჭრა', AssessmentResult::query()->where('assessment_id', $second->id)->value('final_grade'));
        $this->assertNotSame($first->grade_scale_id, $second->grade_scale_id);
    }

    public function test_scale_must_cover_zero_percent_and_only_management_can_create_it(): void
    {
        $f = $this->fixtures();

        $this->actingAs($f['admin'])->post('/portal/assessment-settings/scales', [
            'name' => 'ხარვეზიანი', 'bands' => [['min_percent' => 50, 'label' => 'C']],
        ])->assertSessionHasErrors('bands');

        $this->actingAs($f['teacher'])->post('/portal/assessment-settings/scales', [
            'name' => 'x', 'bands' => [['min_percent' => 0, 'label' => 'F']],
        ])->assertForbidden();

        $this->actingAs($f['guardian'])->get('/portal/assessment-settings')->assertForbidden();
    }

    public function test_tenant_isolation_on_assessments_periods_and_progress(): void
    {
        $f = $this->fixtures();
        $assessment = $this->createAssessment($f);
        $this->scoreStudent($f['teacher'], $assessment, $f['student'], 5);

        $otherTenant = Tenant::create(['slug' => 'other-school', 'name' => 'Other', 'locale' => 'ka', 'timezone' => 'Asia/Tbilisi', 'is_active' => true]);
        TenantDomain::create(['tenant_id' => $otherTenant->id, 'domain' => 'other-school.test', 'is_primary' => true]);
        $outsider = User::factory()->create();
        foreach ([TenantMembership::ROLE_ADMIN, TenantMembership::ROLE_TEACHER] as $role) {
            TenantMembership::create(['tenant_id' => $otherTenant->id, 'user_id' => $outsider->id, 'role' => $role, 'is_active' => true]);
        }

        $host = ['Host' => 'other-school.test'];
        $base = 'http://other-school.test';

        $this->actingAs($outsider)->get("{$base}/portal/gradebook/assessments/{$assessment->id}", $host)->assertNotFound();
        $this->actingAs($outsider)->post("{$base}/portal/gradebook/assessments/{$assessment->id}/results", [
            'results' => [['student_id' => $f['student']->id, 'raw_score' => 1]],
        ], $host)->assertNotFound();
        $this->actingAs($outsider)->post("{$base}/portal/gradebook/assessments/{$assessment->id}/publish", [], $host)->assertNotFound();
        $this->actingAs($outsider)->get("{$base}/portal/students/{$f['student']->id}/progress", $host)->assertNotFound();
        $this->actingAs($outsider)->post("{$base}/portal/assessment-settings/periods/{$f['period']->id}/close", [], $host)->assertNotFound();

        // The other tenant's settings screen never lists this tenant's data.
        $this->actingAs($outsider)->get("{$base}/portal/assessment-settings", $host)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('periods', [])->where('scales', []));

        $this->assertSame('5.00', AssessmentResult::query()->firstOrFail()->raw_score);
        $this->assertFalse($f['period']->fresh()->isClosed());
    }

    public function test_nav_shows_gradebook_for_teacher_and_settings_for_management(): void
    {
        $f = $this->fixtures();

        $this->actingAs($f['teacher'])->get('/portal/gradebook')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('portal/gradebook/index')
                ->where('portal.navItems', fn ($items) => collect($items)->contains('key', 'gradebook')));

        $this->actingAs($f['admin'])->get('/portal/assessment-settings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('portal.navItems', fn ($items) => collect($items)->contains('key', 'assessment-settings')));
    }
}
