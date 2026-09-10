<?php

namespace Tests\Feature\Portfolio;

use App\Domain\Academics\Models\Student;
use App\Domain\Portfolio\Models\PortfolioItem;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Covers CLAUDE-PLATFORM-MODULES.md §5/§17's required cases: default
 * private visibility, teacher scoped to their own assigned class, a
 * published item never regressing to a guardian-visible draft, and
 * mandatory return feedback.
 */
class PortfolioWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /**
     * @return array{tenant: Tenant, studentUser: User, student: Student, teacher: User, otherTeacher: User, guardian: User}
     */
    private function baseFixtures(): array
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;

        $year = $tenant->academicYears()->create([
            'name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-15', 'is_current' => true,
        ]);
        $class = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => 'VI']);
        $otherClass = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => 'VII']);

        $studentUser = User::factory()->create();
        $student = $tenant->students()->create([
            'school_class_id' => $class->id, 'user_id' => $studentUser->id,
            'first_name' => 'ნიკა', 'last_name' => 'დ.', 'is_active' => true,
        ]);

        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $studentUser->id, 'role' => TenantMembership::ROLE_STUDENT, 'is_active' => true]);

        $teacher = User::factory()->create();
        $otherTeacher = User::factory()->create();
        $guardian = User::factory()->create();

        foreach ([[$teacher, TenantMembership::ROLE_TEACHER], [$otherTeacher, TenantMembership::ROLE_TEACHER], [$guardian, TenantMembership::ROLE_GUARDIAN]] as [$user, $role]) {
            TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role, 'is_active' => true]);
        }

        $tenant->teacherAssignments()->create(['user_id' => $teacher->id, 'school_class_id' => $class->id, 'subject' => 'ხელოვნება']);
        $tenant->teacherAssignments()->create(['user_id' => $otherTeacher->id, 'school_class_id' => $otherClass->id, 'subject' => 'მუსიკა']);

        $tenant->guardianLinks()->create([
            'user_id' => $guardian->id, 'student_id' => $student->id,
            'can_view_academic' => true, 'can_view_financial' => false,
            'can_pickup' => false, 'can_receive_notifications' => true, 'is_active' => true,
        ]);

        return compact('tenant', 'studentUser', 'student', 'teacher', 'otherTeacher', 'guardian');
    }

    public function test_student_can_create_submit_and_get_published(): void
    {
        ['studentUser' => $studentUser, 'student' => $student, 'teacher' => $teacher, 'guardian' => $guardian] = $this->baseFixtures();

        $create = $this->actingAs($studentUser)->post("/portal/students/{$student->id}/portfolio", [
            'title' => 'ჩემი ნახატი',
            'description' => 'პეიზაჟი აკვარელით',
            'file' => UploadedFile::fake()->create('work.pdf', 200, 'application/pdf'),
        ]);
        $item = PortfolioItem::query()->first();
        $create->assertRedirect(route('portfolio.show', $item));
        $this->assertSame(PortfolioItem::STATUS_DRAFT, $item->status);

        // Guardian cannot see the draft yet.
        $this->actingAs($guardian)->get("/portal/portfolio/{$item->id}")->assertForbidden();

        $this->actingAs($studentUser)->post("/portal/portfolio/{$item->id}/submit")->assertRedirect();
        $this->assertSame(PortfolioItem::STATUS_SUBMITTED, $item->fresh()->status);

        // Guardian still cannot see a submitted (not yet published) item.
        $this->actingAs($guardian)->get("/portal/portfolio/{$item->id}")->assertForbidden();

        $this->actingAs($teacher)->post("/portal/portfolio/{$item->id}/decide", ['decision' => 'publish'])->assertRedirect();
        $this->assertSame(PortfolioItem::STATUS_PUBLISHED, $item->fresh()->status);

        $this->actingAs($guardian)->get("/portal/portfolio/{$item->id}")->assertOk();
    }

    public function test_submit_requires_at_least_one_asset(): void
    {
        ['studentUser' => $studentUser, 'student' => $student] = $this->baseFixtures();

        $this->actingAs($studentUser)->post("/portal/students/{$student->id}/portfolio", ['title' => 'ცარიელი']);
        $item = PortfolioItem::query()->first();

        $this->actingAs($studentUser)->post("/portal/portfolio/{$item->id}/submit")->assertStatus(422);
        $this->assertSame(PortfolioItem::STATUS_DRAFT, $item->fresh()->status);
    }

    public function test_teacher_not_assigned_to_class_cannot_decide(): void
    {
        ['studentUser' => $studentUser, 'student' => $student, 'otherTeacher' => $otherTeacher] = $this->baseFixtures();

        $this->actingAs($studentUser)->post("/portal/students/{$student->id}/portfolio", [
            'title' => 'ნამუშევარი',
            'file' => UploadedFile::fake()->create('work.pdf', 100, 'application/pdf'),
        ]);
        $item = PortfolioItem::query()->first();
        $this->actingAs($studentUser)->post("/portal/portfolio/{$item->id}/submit");

        $this->actingAs($otherTeacher)->post("/portal/portfolio/{$item->id}/decide", ['decision' => 'publish'])->assertForbidden();
        $this->assertSame(PortfolioItem::STATUS_SUBMITTED, $item->fresh()->status);
    }

    public function test_return_requires_feedback_and_reopens_editing(): void
    {
        ['studentUser' => $studentUser, 'student' => $student, 'teacher' => $teacher] = $this->baseFixtures();

        $this->actingAs($studentUser)->post("/portal/students/{$student->id}/portfolio", [
            'title' => 'ნამუშევარი',
            'file' => UploadedFile::fake()->create('work.pdf', 100, 'application/pdf'),
        ]);
        $item = PortfolioItem::query()->first();
        $this->actingAs($studentUser)->post("/portal/portfolio/{$item->id}/submit");

        // No feedback -> rejected, still submitted.
        $this->actingAs($teacher)->post("/portal/portfolio/{$item->id}/decide", ['decision' => 'return'])
            ->assertRedirect()
            ->assertSessionHasErrors('feedback');
        $this->assertSame(PortfolioItem::STATUS_SUBMITTED, $item->fresh()->status);

        $this->actingAs($teacher)->post("/portal/portfolio/{$item->id}/decide", [
            'decision' => 'return', 'feedback' => 'გთხოვთ დაამატოთ მეტი დეტალი.',
        ])->assertRedirect();

        $item->refresh();
        $this->assertSame(PortfolioItem::STATUS_RETURNED, $item->status);
        $this->assertTrue($item->isEditableByStudent());
        $this->assertSame(1, $item->feedback()->count());
    }

    public function test_tenant_isolation_on_portfolio_item(): void
    {
        ['studentUser' => $studentUser, 'student' => $student] = $this->baseFixtures();

        $this->actingAs($studentUser)->post("/portal/students/{$student->id}/portfolio", ['title' => 'ჩემი', 'file' => UploadedFile::fake()->create('w.pdf', 50, 'application/pdf')]);
        $item = PortfolioItem::query()->first();

        $otherTenant = Tenant::create(['slug' => 'other-school', 'name' => 'Other', 'locale' => 'ka', 'timezone' => 'Asia/Tbilisi', 'is_active' => true]);
        TenantDomain::create(['tenant_id' => $otherTenant->id, 'domain' => 'other-school.test', 'is_primary' => true]);
        $outsider = User::factory()->create();
        TenantMembership::create(['tenant_id' => $otherTenant->id, 'user_id' => $outsider->id, 'role' => TenantMembership::ROLE_ADMIN, 'is_active' => true]);

        $this->actingAs($outsider)
            ->get("http://other-school.test/portal/portfolio/{$item->id}", ['Host' => 'other-school.test'])
            ->assertNotFound();
    }
}
