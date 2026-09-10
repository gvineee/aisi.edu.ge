<?php

namespace Tests\Feature\Portal;

use App\Domain\Academics\Models\Student;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers docs/02 critical check #2: a guardian only ever sees their own,
 * actively-linked child, and revoking the link hides it immediately.
 */
class ParentDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function makeStudent(string $name): Student
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;

        $year = $tenant->academicYears()->firstOrCreate(
            ['name' => '2026-2027'],
            ['starts_on' => '2026-09-01', 'ends_on' => '2027-06-15', 'is_current' => true],
        );

        $class = $tenant->schoolClasses()->firstOrCreate(
            ['academic_year_id' => $year->id, 'name' => 'VI'],
        );

        return $tenant->students()->create([
            'school_class_id' => $class->id,
            'first_name' => $name, 'last_name' => 'Test', 'is_active' => true,
        ]);
    }

    public function test_a_guardian_sees_only_their_own_linked_child(): void
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;
        $myChild = $this->makeStudent('MyChild');
        $someoneElsesChild = $this->makeStudent('OtherChild');

        $guardian = User::factory()->create();
        TenantMembership::create([
            'tenant_id' => $tenant->id, 'user_id' => $guardian->id,
            'role' => TenantMembership::ROLE_GUARDIAN, 'is_active' => true,
        ]);
        $tenant->guardianLinks()->create([
            'user_id' => $guardian->id, 'student_id' => $myChild->id,
            'can_view_academic' => true, 'is_active' => true,
        ]);

        $response = $this->actingAs($guardian)->get(route('dashboard'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('portal/parent-dashboard')
            ->has('children', 1)
            ->where('children.0.name', 'MyChild Test')
        );
        $response->assertDontSee('OtherChild');
    }

    public function test_revoking_the_guardian_link_hides_the_child_immediately(): void
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;
        $child = $this->makeStudent('RevokedChild');

        $guardian = User::factory()->create();
        TenantMembership::create([
            'tenant_id' => $tenant->id, 'user_id' => $guardian->id,
            'role' => TenantMembership::ROLE_GUARDIAN, 'is_active' => true,
        ]);
        $link = $tenant->guardianLinks()->create([
            'user_id' => $guardian->id, 'student_id' => $child->id,
            'can_view_academic' => true, 'is_active' => true,
        ]);

        $this->actingAs($guardian)
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->has('children', 1));

        $link->update(['is_active' => false]);

        $this->actingAs($guardian)
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->has('children', 0));
    }

    public function test_a_user_with_no_role_gets_an_honest_empty_state(): void
    {
        $user = User::factory()->create(['name' => 'No Role Person']);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('portal/no-role'));
    }
}
