<?php

namespace Tests\Feature\Cms;

use App\Domain\Content\Models\Teacher;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Teachers are a dedicated block (their own admin screen, a real individual
 * public page, and a homepage carousel) separate from the general CMS
 * Page/Post models — same role-gate/tenant-isolation/publish-visibility
 * requirements as the rest of CMS.
 */
class TeacherWorkflowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Tenant, 1: User}
     */
    private function editor(): array
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;
        $editor = User::factory()->create();
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $editor->id, 'role' => TenantMembership::ROLE_EDITOR, 'is_active' => true]);

        return [$tenant, $editor];
    }

    public function test_editor_can_create_a_teacher_with_a_georgian_slug(): void
    {
        [$tenant, $editor] = $this->editor();

        $this->actingAs($editor)->post('/portal/teachers', [
            'name' => 'სოფიკო ტატულაშვილი',
            'subject' => 'დაწყებითის პედაგოგი',
        ])->assertRedirect();

        $teacher = Teacher::query()->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame('სოფიკო-ტატულაშვილი', $teacher->slug);
        $this->assertSame(Teacher::STATUS_DRAFT, $teacher->status);
        $this->assertNull($teacher->photo_path);
    }

    public function test_creating_a_teacher_with_a_photo_stores_it_on_the_public_disk(): void
    {
        Storage::fake('public');
        [, $editor] = $this->editor();

        $this->actingAs($editor)->post('/portal/teachers', [
            'name' => 'გიორგი გიორგაძე',
            'subject' => 'მათემატიკა',
            'photo' => UploadedFile::fake()->image('teacher.jpg'),
        ])->assertRedirect();

        $teacher = Teacher::query()->where('name', 'გიორგი გიორგაძე')->firstOrFail();
        $this->assertNotNull($teacher->photo_path);
        Storage::disk('public')->assertExists($teacher->photo_path);
    }

    public function test_duplicate_names_get_a_unique_slug(): void
    {
        [$tenant, $editor] = $this->editor();

        $this->actingAs($editor)->post('/portal/teachers', ['name' => 'ანა ანანიძე', 'subject' => 'ისტორია']);
        $this->actingAs($editor)->post('/portal/teachers', ['name' => 'ანა ანანიძე', 'subject' => 'გეოგრაფია']);

        $slugs = Teacher::query()->where('tenant_id', $tenant->id)->pluck('slug')->all();
        $this->assertSame(['ანა-ანანიძე', 'ანა-ანანიძე-2'], $slugs);
    }

    public function test_non_staff_role_is_forbidden(): void
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;
        $guardian = User::factory()->create();
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $guardian->id, 'role' => TenantMembership::ROLE_GUARDIAN, 'is_active' => true]);

        $this->actingAs($guardian)->get('/portal/teachers')->assertForbidden();
    }

    public function test_publish_and_unpublish_change_public_visibility(): void
    {
        [$tenant, $editor] = $this->editor();

        $teacher = $tenant->teachers()->create([
            'slug' => 'temp-teacher', 'name' => 'დროებითი', 'subject' => 'ქართული', 'status' => Teacher::STATUS_DRAFT,
        ]);

        $this->get("/teachers/{$teacher->slug}")->assertNotFound();

        $this->actingAs($editor)->post(route('teachers.publish', $teacher))->assertRedirect();
        $this->assertSame(Teacher::STATUS_PUBLISHED, $teacher->fresh()->status);
        $this->get("/teachers/{$teacher->slug}")->assertOk()
            ->assertInertia(fn ($assert) => $assert->where('teacher.name', 'დროებითი'));

        $this->actingAs($editor)->post(route('teachers.unpublish', $teacher))->assertRedirect();
        $this->assertSame(Teacher::STATUS_DRAFT, $teacher->fresh()->status);
        $this->get("/teachers/{$teacher->slug}")->assertNotFound();
    }

    public function test_unpublished_teacher_is_excluded_from_the_public_index(): void
    {
        [$tenant, $editor] = $this->editor();

        $published = $tenant->teachers()->create(['slug' => 'published-teacher', 'name' => 'გამოქვეყნებული', 'subject' => 'ფიზიკა', 'status' => Teacher::STATUS_PUBLISHED]);
        $tenant->teachers()->create(['slug' => 'draft-teacher', 'name' => 'მონახაზი', 'subject' => 'ქიმია', 'status' => Teacher::STATUS_DRAFT]);

        $this->get('/teachers')->assertOk()->assertInertia(fn ($assert) => $assert
            ->has('teachers', 1)
            ->where('teachers.0.slug', $published->slug));
    }

    public function test_tenant_isolation_on_teacher_edit(): void
    {
        [$tenant, $editor] = $this->editor();

        $teacher = $tenant->teachers()->create(['slug' => 'isolated-teacher', 'name' => 'იზოლირებული', 'subject' => 'ბიოლოგია', 'status' => Teacher::STATUS_DRAFT]);

        $otherTenant = Tenant::create(['slug' => 'other-school-teachers', 'name' => 'Other', 'locale' => 'ka', 'timezone' => 'Asia/Tbilisi', 'is_active' => true]);
        TenantDomain::create(['tenant_id' => $otherTenant->id, 'domain' => 'other-school-teachers.test', 'is_primary' => true]);
        $outsider = User::factory()->create();
        TenantMembership::create(['tenant_id' => $otherTenant->id, 'user_id' => $outsider->id, 'role' => TenantMembership::ROLE_ADMIN, 'is_active' => true]);

        $this->actingAs($outsider)
            ->get("http://other-school-teachers.test/portal/teachers/{$teacher->id}/edit", ['Host' => 'other-school-teachers.test'])
            ->assertNotFound();
    }
}
