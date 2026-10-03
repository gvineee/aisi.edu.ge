<?php

namespace Tests\Feature\Assessment;

use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Assessment\Models\Assessment;
use App\Domain\Assessment\Models\AssessmentPeriod;
use App\Domain\Assessment\Models\ReportCard;
use App\Domain\Assessment\Models\ReportCardPublication;
use App\Domain\Assessment\Models\ReportCardSubject;
use App\Domain\Governance\Models\AuditEvent;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Report card workflow (CLAUDE-PLATFORM-MODULES §6/§17): draft →
 * teacher_complete → homeroom_review → director_approved → published, each
 * step gated to its role; families see only frozen publications; revisions
 * keep the old publication visible; verification shows no marks; tenant
 * isolation and guardian revocation.
 */
class ReportCardWorkflowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function fixtures(): array
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;

        $year = $tenant->academicYears()->create([
            'name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-15', 'is_current' => true,
        ]);
        $class = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => 'VI']);
        $otherClass = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => 'VII']);

        $users = [];
        foreach ([
            'manager' => TenantMembership::ROLE_ACADEMIC_MANAGER,
            'director' => TenantMembership::ROLE_DIRECTOR,
            'math' => TenantMembership::ROLE_TEACHER,
            'history' => TenantMembership::ROLE_TEACHER,
            'outsiderTeacher' => TenantMembership::ROLE_TEACHER,
            'studentUser' => TenantMembership::ROLE_STUDENT,
            'guardian' => TenantMembership::ROLE_GUARDIAN,
        ] as $key => $role) {
            $users[$key] = User::factory()->create();
            TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $users[$key]->id, 'role' => $role, 'is_active' => true]);
        }

        $mathTa = $tenant->teacherAssignments()->create(['user_id' => $users['math']->id, 'school_class_id' => $class->id, 'subject' => 'მათემატიკა']);
        $historyTa = $tenant->teacherAssignments()->create(['user_id' => $users['history']->id, 'school_class_id' => $class->id, 'subject' => 'ისტორია']);
        $tenant->teacherAssignments()->create(['user_id' => $users['outsiderTeacher']->id, 'school_class_id' => $otherClass->id, 'subject' => 'ქიმია']);

        $student = $tenant->students()->create([
            'school_class_id' => $class->id, 'user_id' => $users['studentUser']->id,
            'first_name' => 'ნიკა', 'last_name' => 'დოლიძე', 'is_active' => true,
        ]);

        $link = new GuardianLink([
            'user_id' => $users['guardian']->id, 'student_id' => $student->id,
            'can_view_academic' => true, 'can_view_financial' => false, 'can_pickup' => true,
            'can_receive_notifications' => true, 'is_active' => true,
        ]);
        $link->tenant_id = $tenant->id;
        $link->save();

        // Homeroom = the maths teacher.
        $this->actingAs($users['manager'])
            ->post("/portal/academic-structure/school-classes/{$class->id}/homeroom", ['homeroom_teacher_id' => $users['math']->id])
            ->assertRedirect();

        $this->actingAs($users['manager'])->post('/portal/assessment-settings/periods', [
            'academic_year_id' => $year->id, 'name' => 'I სემესტრი', 'starts_on' => '2026-09-01', 'ends_on' => '2027-01-31',
        ]);
        $period = AssessmentPeriod::query()->firstOrFail();

        // One published maths result so the summary has data.
        $this->actingAs($users['math'])->post('/portal/gradebook/assessments', [
            'teacher_assignment_id' => $mathTa->id, 'assessment_period_id' => $period->id,
            'title' => 'ტესტი', 'assessed_on' => '2026-10-01', 'max_score' => 10,
        ]);
        $assessment = Assessment::query()->firstOrFail();
        $this->actingAs($users['math'])->post("/portal/gradebook/assessments/{$assessment->id}/results", [
            'results' => [['student_id' => $student->id, 'raw_score' => 8]],
        ]);
        $this->actingAs($users['math'])->post("/portal/gradebook/assessments/{$assessment->id}/publish");

        return $users + compact('tenant', 'class', 'period', 'student', 'mathTa', 'historyTa');
    }

    /**
     * @param  array<string, mixed>  $f
     */
    private function generate(array $f): ReportCard
    {
        $this->actingAs($f['manager'])->post("/portal/assessment-settings/periods/{$f['period']->id}/close");
        $this->actingAs($f['manager'])->post('/portal/report-cards/generate', [
            'school_class_id' => $f['class']->id, 'assessment_period_id' => $f['period']->id,
        ])->assertSessionHasNoErrors();

        return ReportCard::query()->firstOrFail();
    }

    private function line(ReportCard $card, int $teacherAssignmentId): ReportCardSubject
    {
        return ReportCardSubject::query()->where('report_card_id', $card->id)->where('teacher_assignment_id', $teacherAssignmentId)->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $f
     */
    private function completeLines(array $f, ReportCard $card): void
    {
        foreach ([['math', 'mathTa', 'A'], ['history', 'historyTa', 'B']] as [$teacher, $ta, $mark]) {
            $this->actingAs($f[$teacher])->post("/portal/report-cards/{$card->id}/lines/{$this->line($card, $f[$ta]->id)->id}", [
                'final_mark' => $mark, 'teacher_comment' => 'კარგი პროგრესი', 'complete' => true,
            ])->assertSessionHasNoErrors();
        }
    }

    /**
     * @param  array<string, mixed>  $f
     */
    private function publishCard(array $f, ReportCard $card): ReportCardPublication
    {
        $this->completeLines($f, $card);
        $this->actingAs($f['math'])->post("/portal/report-cards/{$card->id}/homeroom-submit", ['comment' => 'აქტიური და გულისხმიერი'])->assertSessionHasNoErrors();
        $this->actingAs($f['director'])->post("/portal/report-cards/{$card->id}/approve", ['comment' => 'დამტკიცებულია'])->assertSessionHasNoErrors();
        $this->actingAs($f['director'])->post("/portal/report-cards/{$card->id}/publish")->assertSessionHasNoErrors();

        return ReportCardPublication::query()->latest('id')->firstOrFail();
    }

    public function test_generation_requires_a_closed_period_and_management_role(): void
    {
        $f = $this->fixtures();

        $this->actingAs($f['manager'])->post('/portal/report-cards/generate', [
            'school_class_id' => $f['class']->id, 'assessment_period_id' => $f['period']->id,
        ])->assertSessionHasErrors('assessment_period_id');

        $this->actingAs($f['math'])->post('/portal/report-cards/generate', [
            'school_class_id' => $f['class']->id, 'assessment_period_id' => $f['period']->id,
        ])->assertForbidden();

        $card = $this->generate($f);
        $this->assertSame(ReportCard::STATUS_DRAFT, $card->status);
        $this->assertSame(2, $card->subjects()->count());

        $math = $this->line($card, $f['mathTa']->id);
        $this->assertSame(1, $math->assessments_count);
        $this->assertSame('80.00', $math->average_percent);
    }

    public function test_full_workflow_publishes_a_frozen_document_families_can_read(): void
    {
        $f = $this->fixtures();
        $card = $this->generate($f);

        // Families see nothing before publication.
        $this->actingAs($f['guardian'])->get("/portal/students/{$f['student']->id}/report-cards")
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('publications', []));

        // A teacher only edits their own line.
        $this->actingAs($f['history'])->post("/portal/report-cards/{$card->id}/lines/{$this->line($card, $f['mathTa']->id)->id}", [
            'final_mark' => 'F', 'complete' => true,
        ])->assertForbidden();

        $this->completeLines($f, $card);
        $this->assertSame(ReportCard::STATUS_TEACHER_COMPLETE, $card->fresh()->status);

        // A subject teacher who is not the homeroom cannot review; homeroom comment is required.
        $this->actingAs($f['history'])->post("/portal/report-cards/{$card->id}/homeroom-submit", ['comment' => 'x'])->assertForbidden();
        $this->actingAs($f['math'])->post("/portal/report-cards/{$card->id}/homeroom-submit", ['comment' => ''])->assertSessionHasErrors('comment');
        $this->actingAs($f['math'])->post("/portal/report-cards/{$card->id}/homeroom-submit", ['comment' => 'აქტიური'])->assertSessionHasNoErrors();
        $this->assertSame(ReportCard::STATUS_HOMEROOM_REVIEW, $card->fresh()->status);

        // Only the director approves; publishing before approval is rejected.
        $this->actingAs($f['manager'])->post("/portal/report-cards/{$card->id}/approve")->assertForbidden();
        $this->actingAs($f['director'])->post("/portal/report-cards/{$card->id}/publish")->assertSessionHasErrors('status');
        $this->actingAs($f['director'])->post("/portal/report-cards/{$card->id}/approve", ['comment' => 'კარგია'])->assertSessionHasNoErrors();
        $this->actingAs($f['director'])->post("/portal/report-cards/{$card->id}/publish")->assertSessionHasNoErrors();
        $this->assertSame(ReportCard::STATUS_PUBLISHED, $card->fresh()->status);

        $publication = ReportCardPublication::query()->firstOrFail();
        $marks = collect($publication->snapshot['subjects'])->pluck('finalMark', 'subject');
        $this->assertSame('A', $marks['მათემატიკა']);
        $this->assertSame('B', $marks['ისტორია']);
        $this->assertSame('აქტიური', $publication->snapshot['homeroomComment']);
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{12}$/', $publication->verification_code);

        foreach ([$f['guardian'], $f['studentUser']] as $viewer) {
            $this->actingAs($viewer)->get("/portal/students/{$f['student']->id}/report-cards")
                ->assertOk()->assertInertia(fn (Assert $page) => $page->where('publications.0.id', $publication->id));
            $this->actingAs($viewer)->get("/portal/report-card-documents/{$publication->id}")
                ->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('portal/report-cards/document')
                ->where('document.studentName', 'ნიკა დოლიძე')
                ->where('isLatest', true));
        }

        // Server-side PDF from the snapshot, for the same viewers only.
        $pdf = $this->actingAs($f['guardian'])->get("/portal/report-card-documents/{$publication->id}/pdf");
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', (string) $pdf->getContent());
        $this->assertStringContainsString('private', (string) $pdf->headers->get('Cache-Control'));
        $this->actingAs($f['outsiderTeacher'])->get("/portal/report-card-documents/{$publication->id}/pdf")->assertForbidden();

        // A teacher of another class cannot open it.
        $this->actingAs($f['outsiderTeacher'])->get("/portal/report-card-documents/{$publication->id}")->assertForbidden();
        $this->actingAs($f['outsiderTeacher'])->get("/portal/report-cards/{$card->id}")->assertForbidden();

        // draft→teacher_complete→homeroom_review→director_approved→published
        $this->assertSame(4, AuditEvent::query()->where('action', 'report_card.transitioned')->count());
    }

    public function test_subject_teacher_sees_only_own_line_and_homeroom_sees_all(): void
    {
        $f = $this->fixtures();
        $card = $this->generate($f);

        $this->actingAs($f['history'])->get("/portal/report-cards/{$card->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('lines', 1)->where('lines.0.subject', 'ისტორია')->where('history', []));

        $this->actingAs($f['math'])->get("/portal/report-cards/{$card->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('lines', 2));
    }

    public function test_return_reopens_selected_lines_and_requires_reason(): void
    {
        $f = $this->fixtures();
        $card = $this->generate($f);
        $this->completeLines($f, $card);
        $this->actingAs($f['math'])->post("/portal/report-cards/{$card->id}/homeroom-submit", ['comment' => 'კარგი']);

        $historyLine = $this->line($card, $f['historyTa']->id);

        $this->actingAs($f['director'])->post("/portal/report-cards/{$card->id}/return", ['reason' => '', 'subject_ids' => [$historyLine->id]])
            ->assertSessionHasErrors('reason');

        $this->actingAs($f['director'])->post("/portal/report-cards/{$card->id}/return", [
            'reason' => 'ისტორიის კომენტარი დასაზუსტებელია', 'subject_ids' => [$historyLine->id],
        ])->assertSessionHasNoErrors();

        $this->assertSame(ReportCard::STATUS_DRAFT, $card->fresh()->status);
        $this->assertNull($historyLine->fresh()->completed_at);
        $this->assertNotNull($this->line($card, $f['mathTa']->id)->completed_at);
    }

    public function test_revision_keeps_old_publication_visible_until_republished(): void
    {
        $f = $this->fixtures();
        $card = $this->generate($f);
        $first = $this->publishCard($f, $card);

        $this->actingAs($f['math'])->post("/portal/report-cards/{$card->id}/revise", ['reason' => 'x'])->assertForbidden();

        $mathLine = $this->line($card, $f['mathTa']->id);
        $this->actingAs($f['director'])->post("/portal/report-cards/{$card->id}/revise", [
            'reason' => 'მათემატიკის შეფასების შესწორება', 'subject_ids' => [$mathLine->id],
        ])->assertSessionHasNoErrors();

        $card->refresh();
        $this->assertSame(2, $card->revision);
        $this->assertSame(ReportCard::STATUS_DRAFT, $card->status);

        // Family still sees revision 1.
        $this->actingAs($f['guardian'])->get("/portal/students/{$f['student']->id}/report-cards")
            ->assertInertia(fn (Assert $page) => $page->where('publications.0.id', $first->id));

        $this->actingAs($f['math'])->post("/portal/report-cards/{$card->id}/lines/{$mathLine->id}", [
            'final_mark' => 'A+', 'complete' => true,
        ])->assertSessionHasNoErrors();
        $this->actingAs($f['math'])->post("/portal/report-cards/{$card->id}/homeroom-submit", ['comment' => 'შესწორდა']);
        $this->actingAs($f['director'])->post("/portal/report-cards/{$card->id}/approve");
        $this->actingAs($f['director'])->post("/portal/report-cards/{$card->id}/publish")->assertSessionHasNoErrors();

        $second = ReportCardPublication::query()->where('revision', 2)->firstOrFail();
        $this->assertNotSame($first->verification_code, $second->verification_code);

        $this->actingAs($f['guardian'])->get("/portal/students/{$f['student']->id}/report-cards")
            ->assertInertia(fn (Assert $page) => $page->has('publications', 1)->where('publications.0.id', $second->id));

        // The old printed copy still verifies, but is flagged as superseded.
        $this->get("/verify/report-card/{$first->verification_code}")
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertInertia(fn (Assert $page) => $page
                ->where('result.isLatest', false)
                ->where('result.studentInitials', 'ნ. დ.')
                ->missing('result.subjects'));
    }

    public function test_revoked_guardian_loses_access_to_published_documents(): void
    {
        $f = $this->fixtures();
        $publication = $this->publishCard($f, $this->generate($f));

        GuardianLink::query()->where('user_id', $f['guardian']->id)->update(['is_active' => false]);

        $this->actingAs($f['guardian'])->get("/portal/report-card-documents/{$publication->id}")->assertForbidden();
        $this->actingAs($f['guardian'])->get("/portal/students/{$f['student']->id}/report-cards")->assertForbidden();
    }

    public function test_tenant_isolation_on_cards_documents_and_verification(): void
    {
        $f = $this->fixtures();
        $card = $this->generate($f);
        $publication = $this->publishCard($f, $card);

        $otherTenant = Tenant::create(['slug' => 'other-school', 'name' => 'Other', 'locale' => 'ka', 'timezone' => 'Asia/Tbilisi', 'is_active' => true]);
        TenantDomain::create(['tenant_id' => $otherTenant->id, 'domain' => 'other-school.test', 'is_primary' => true]);
        $outsider = User::factory()->create();
        foreach ([TenantMembership::ROLE_DIRECTOR, TenantMembership::ROLE_TEACHER] as $role) {
            TenantMembership::create(['tenant_id' => $otherTenant->id, 'user_id' => $outsider->id, 'role' => $role, 'is_active' => true]);
        }

        $host = ['Host' => 'other-school.test'];
        $base = 'http://other-school.test';

        $this->actingAs($outsider)->get("{$base}/portal/report-cards/{$card->id}", $host)->assertNotFound();
        $this->actingAs($outsider)->post("{$base}/portal/report-cards/{$card->id}/revise", ['reason' => 'სხვა სკოლიდან'], $host)->assertNotFound();
        $this->actingAs($outsider)->get("{$base}/portal/report-card-documents/{$publication->id}", $host)->assertNotFound();
        $this->actingAs($outsider)->get("{$base}/portal/students/{$f['student']->id}/report-cards", $host)->assertNotFound();

        // A code from school A does not verify on school B's domain.
        $this->get("{$base}/verify/report-card/{$publication->verification_code}", $host)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('result', null));

        $this->assertSame(ReportCard::STATUS_PUBLISHED, $card->fresh()->status);
    }

    public function test_homeroom_must_be_an_active_teacher(): void
    {
        $f = $this->fixtures();

        $this->actingAs($f['manager'])
            ->post("/portal/academic-structure/school-classes/{$f['class']->id}/homeroom", ['homeroom_teacher_id' => $f['guardian']->id])
            ->assertSessionHasErrors('homeroom_teacher_id');

        $this->assertSame($f['math']->id, SchoolClass::query()->find($f['class']->id)->homeroom_teacher_id);
        $this->assertTrue(AuditEvent::query()->where('action', 'school_class.homeroom_assigned')->exists());
    }

    public function test_private_areas_are_noindex_and_disallowed_in_robots(): void
    {
        $f = $this->fixtures();

        $this->actingAs($f['manager'])->get('/portal/report-cards')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get('/')->assertHeaderMissing('X-Robots-Tag');

        $robots = $this->get('/robots.txt')->assertOk()->getContent();
        $this->assertStringContainsString('Disallow: /portal/', (string) $robots);
        $this->assertStringContainsString('Disallow: /verify/', (string) $robots);
    }

    public function test_student_record_without_link_is_forbidden_from_others_report_cards(): void
    {
        $f = $this->fixtures();
        $publication = $this->publishCard($f, $this->generate($f));

        $otherStudentUser = User::factory()->create();
        TenantMembership::create(['tenant_id' => $f['tenant']->id, 'user_id' => $otherStudentUser->id, 'role' => TenantMembership::ROLE_STUDENT, 'is_active' => true]);
        $f['tenant']->students()->create([
            'school_class_id' => $f['class']->id, 'user_id' => $otherStudentUser->id,
            'first_name' => 'გიორგი', 'last_name' => 'მ.', 'is_active' => true,
        ]);

        $this->actingAs($otherStudentUser)->get("/portal/report-card-documents/{$publication->id}")->assertForbidden();
    }
}
