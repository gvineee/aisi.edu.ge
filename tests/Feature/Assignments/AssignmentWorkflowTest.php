<?php

namespace Tests\Feature\Assignments;

use App\Domain\Academics\Models\Student;
use App\Domain\Learning\Models\Assignment;
use App\Domain\Learning\Models\AssignmentSubmission;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Covers the Assignments & Submissions module's required cases: a teacher
 * only ever manages assignments on their own teacher_assignment, a draft
 * assignment is invisible to students, a student can only ever submit as
 * their own enrollment, being late only ever sets a flag (never blocks),
 * and tenant isolation on both new tables.
 */
class AssignmentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /**
     * @return array{tenant: Tenant, teacher: User, otherTeacher: User, teacherAssignmentId: int, otherTeacherAssignmentId: int, studentUser: User, student: Student, otherStudentUser: User, otherStudent: Student}
     */
    private function baseFixtures(): array
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;

        $year = $tenant->academicYears()->create([
            'name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-15', 'is_current' => true,
        ]);
        $class = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => 'VI']);
        $otherClass = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => 'VII']);

        $teacher = User::factory()->create();
        $otherTeacher = User::factory()->create();

        foreach ([$teacher, $otherTeacher] as $user) {
            TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => TenantMembership::ROLE_TEACHER, 'is_active' => true]);
        }

        $teacherAssignment = $tenant->teacherAssignments()->create(['user_id' => $teacher->id, 'school_class_id' => $class->id, 'subject' => 'მათემატიკა']);
        $otherTeacherAssignment = $tenant->teacherAssignments()->create(['user_id' => $otherTeacher->id, 'school_class_id' => $otherClass->id, 'subject' => 'ისტორია']);

        $studentUser = User::factory()->create();
        $student = $tenant->students()->create([
            'school_class_id' => $class->id, 'user_id' => $studentUser->id,
            'first_name' => 'ნიკა', 'last_name' => 'დ.', 'is_active' => true,
        ]);
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $studentUser->id, 'role' => TenantMembership::ROLE_STUDENT, 'is_active' => true]);

        $otherStudentUser = User::factory()->create();
        $otherStudent = $tenant->students()->create([
            'school_class_id' => $class->id, 'user_id' => $otherStudentUser->id,
            'first_name' => 'გიორგი', 'last_name' => 'მ.', 'is_active' => true,
        ]);
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $otherStudentUser->id, 'role' => TenantMembership::ROLE_STUDENT, 'is_active' => true]);

        return [
            'tenant' => $tenant,
            'teacher' => $teacher,
            'otherTeacher' => $otherTeacher,
            'teacherAssignmentId' => $teacherAssignment->id,
            'otherTeacherAssignmentId' => $otherTeacherAssignment->id,
            'studentUser' => $studentUser,
            'student' => $student,
            'otherStudentUser' => $otherStudentUser,
            'otherStudent' => $otherStudent,
        ];
    }

    public function test_full_happy_path_create_publish_submit_grade(): void
    {
        $f = $this->baseFixtures();

        $create = $this->actingAs($f['teacher'])->post('/portal/assignments', [
            'teacher_assignment_id' => $f['teacherAssignmentId'],
            'title' => 'შესავალი წილადებში',
            'description' => 'გვერდები 12-15',
            'max_score' => 10,
        ]);
        $create->assertRedirect(route('assignments.index'));
        $assignment = Assignment::query()->first();
        $this->assertSame(Assignment::STATUS_DRAFT, $assignment->status);

        $this->actingAs($f['teacher'])->post("/portal/assignments/{$assignment->id}/publish")->assertRedirect();
        $this->assertSame(Assignment::STATUS_PUBLISHED, $assignment->fresh()->status);

        $submit = $this->actingAs($f['studentUser'])->post("/portal/my-assignments/{$assignment->id}/submit", [
            'text_response' => 'ჩემი პასუხია ეს.',
        ]);
        $submit->assertRedirect(route('my-assignments.index'));

        $submission = AssignmentSubmission::query()->where('assignment_id', $assignment->id)->where('student_id', $f['student']->id)->first();
        $this->assertNotNull($submission);
        $this->assertSame(AssignmentSubmission::STATUS_SUBMITTED, $submission->status);
        $this->assertFalse($submission->is_late);

        $this->actingAs($f['teacher'])
            ->post("/portal/assignments/{$assignment->id}/submissions/{$submission->id}/grade", [
                'score' => 9,
                'feedback' => 'ძალიან კარგია!',
            ])
            ->assertRedirect();

        $submission->refresh();
        $this->assertSame(AssignmentSubmission::STATUS_GRADED, $submission->status);
        $this->assertSame(9, $submission->score);
        $this->assertSame('ძალიან კარგია!', $submission->feedback);
        $this->assertSame($f['teacher']->id, $submission->graded_by);
        $this->assertNotNull($submission->graded_at);
    }

    public function test_submission_accepts_a_file(): void
    {
        $f = $this->baseFixtures();

        $assignment = $this->createPublishedAssignment($f['teacher'], $f['teacherAssignmentId']);

        $this->actingAs($f['studentUser'])->post("/portal/my-assignments/{$assignment->id}/submit", [
            'file' => UploadedFile::fake()->create('homework.pdf', 100, 'application/pdf'),
        ])->assertRedirect();

        $submission = AssignmentSubmission::query()->where('assignment_id', $assignment->id)->first();
        $this->assertNotNull($submission->file_path);
        Storage::disk('local')->assertExists($submission->file_path);
    }

    public function test_draft_assignment_is_invisible_to_students(): void
    {
        $f = $this->baseFixtures();

        $create = $this->actingAs($f['teacher'])->post('/portal/assignments', [
            'teacher_assignment_id' => $f['teacherAssignmentId'],
            'title' => 'ჯერ არ გამოქვეყნებული',
        ]);
        $assignment = Assignment::query()->first();

        $this->actingAs($f['studentUser'])
            ->get('/portal/my-assignments')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('assignments', []));

        $this->actingAs($f['studentUser'])
            ->post("/portal/my-assignments/{$assignment->id}/submit", ['text_response' => 'მცდელობა'])
            ->assertNotFound();

        $this->assertSame(0, AssignmentSubmission::query()->count());
    }

    public function test_teacher_not_owning_the_assignment_cannot_publish_view_roster_or_grade(): void
    {
        $f = $this->baseFixtures();

        $assignment = $this->createPublishedAssignment($f['teacher'], $f['teacherAssignmentId']);

        $this->actingAs($f['studentUser'])->post("/portal/my-assignments/{$assignment->id}/submit", [
            'text_response' => 'პასუხი',
        ]);
        $submission = AssignmentSubmission::query()->where('assignment_id', $assignment->id)->first();

        $this->actingAs($f['otherTeacher'])->post("/portal/assignments/{$assignment->id}/unpublish")->assertForbidden();
        $this->actingAs($f['otherTeacher'])->get("/portal/assignments/{$assignment->id}/submissions")->assertForbidden();
        $this->actingAs($f['otherTeacher'])
            ->post("/portal/assignments/{$assignment->id}/submissions/{$submission->id}/grade", ['score' => 5])
            ->assertForbidden();

        $this->assertSame(Assignment::STATUS_PUBLISHED, $assignment->fresh()->status);
        $this->assertSame(AssignmentSubmission::STATUS_SUBMITTED, $submission->fresh()->status);
    }

    public function test_a_student_cannot_submit_as_another_student(): void
    {
        $f = $this->baseFixtures();

        $assignment = $this->createPublishedAssignment($f['teacher'], $f['teacherAssignmentId']);

        // Even if the payload tries to name another student, the submission
        // is always recorded under the authenticated user's own Student
        // record — there is no client-supplied student_id the server trusts.
        $this->actingAs($f['studentUser'])->post("/portal/my-assignments/{$assignment->id}/submit", [
            'text_response' => 'ჩემი პასუხი',
            'student_id' => $f['otherStudent']->id,
        ])->assertRedirect();

        $this->assertSame(1, AssignmentSubmission::query()->where('student_id', $f['student']->id)->count());
        $this->assertSame(0, AssignmentSubmission::query()->where('student_id', $f['otherStudent']->id)->count());

        // A user with no Student record at all (a teacher account) cannot submit.
        $this->actingAs($f['otherTeacher'])
            ->post("/portal/my-assignments/{$assignment->id}/submit", ['text_response' => 'x'])
            ->assertForbidden();
    }

    public function test_late_flag_is_set_when_submitted_after_due_at_but_never_blocks_submission(): void
    {
        $f = $this->baseFixtures();

        $pastDue = $this->actingAs($f['teacher'])->post('/portal/assignments', [
            'teacher_assignment_id' => $f['teacherAssignmentId'],
            'title' => 'ვადაგასული დავალება',
            'due_at' => Carbon::now()->subDay()->toDateTimeString(),
        ]);
        $assignment = Assignment::query()->first();
        $this->actingAs($f['teacher'])->post("/portal/assignments/{$assignment->id}/publish");

        $submit = $this->actingAs($f['studentUser'])->post("/portal/my-assignments/{$assignment->id}/submit", [
            'text_response' => 'გვიან, მაგრამ გავაკეთე',
        ]);
        $submit->assertRedirect();

        $submission = AssignmentSubmission::query()->where('assignment_id', $assignment->id)->first();
        $this->assertSame(AssignmentSubmission::STATUS_SUBMITTED, $submission->status);
        $this->assertTrue($submission->is_late);
    }

    public function test_grade_is_rejected_when_it_exceeds_the_assignments_max_score(): void
    {
        $f = $this->baseFixtures();

        $create = $this->actingAs($f['teacher'])->post('/portal/assignments', [
            'teacher_assignment_id' => $f['teacherAssignmentId'],
            'title' => 'ტესტი',
            'max_score' => 10,
        ]);
        $assignment = Assignment::query()->first();
        $this->actingAs($f['teacher'])->post("/portal/assignments/{$assignment->id}/publish");
        $this->actingAs($f['studentUser'])->post("/portal/my-assignments/{$assignment->id}/submit", ['text_response' => 'პასუხი']);
        $submission = AssignmentSubmission::query()->where('assignment_id', $assignment->id)->first();

        $this->actingAs($f['teacher'])
            ->post("/portal/assignments/{$assignment->id}/submissions/{$submission->id}/grade", ['score' => 15])
            ->assertSessionHasErrors('score');

        $this->assertNull($submission->fresh()->score);
        $this->assertSame(AssignmentSubmission::STATUS_SUBMITTED, $submission->fresh()->status);
    }

    public function test_tenant_isolation_on_assignments_and_submissions(): void
    {
        $f = $this->baseFixtures();

        $assignment = $this->createPublishedAssignment($f['teacher'], $f['teacherAssignmentId']);
        $this->actingAs($f['studentUser'])->post("/portal/my-assignments/{$assignment->id}/submit", ['text_response' => 'x']);
        $submission = AssignmentSubmission::query()->where('assignment_id', $assignment->id)->first();

        $otherTenant = Tenant::create(['slug' => 'other-school', 'name' => 'Other', 'locale' => 'ka', 'timezone' => 'Asia/Tbilisi', 'is_active' => true]);
        TenantDomain::create(['tenant_id' => $otherTenant->id, 'domain' => 'other-school.test', 'is_primary' => true]);
        $outsider = User::factory()->create();
        TenantMembership::create(['tenant_id' => $otherTenant->id, 'user_id' => $outsider->id, 'role' => TenantMembership::ROLE_ADMIN, 'is_active' => true]);

        $this->actingAs($outsider)
            ->get("http://other-school.test/portal/assignments/{$assignment->id}/submissions", ['Host' => 'other-school.test'])
            ->assertNotFound();

        $this->actingAs($outsider)
            ->post("http://other-school.test/portal/assignments/{$assignment->id}/submissions/{$submission->id}/grade", ['score' => 1], ['Host' => 'other-school.test'])
            ->assertNotFound();
    }

    private function createPublishedAssignment(User $teacher, int $teacherAssignmentId, ?string $dueAt = null): Assignment
    {
        $payload = [
            'teacher_assignment_id' => $teacherAssignmentId,
            'title' => 'დავალება',
        ];
        if ($dueAt !== null) {
            $payload['due_at'] = $dueAt;
        }

        $this->actingAs($teacher)->post('/portal/assignments', $payload);
        $assignment = Assignment::query()->latest('id')->first();
        $this->actingAs($teacher)->post("/portal/assignments/{$assignment->id}/publish");

        return $assignment->fresh();
    }
}
