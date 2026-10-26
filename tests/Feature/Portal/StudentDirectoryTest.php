<?php

namespace Tests\Feature\Portal;

use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The pupils list for management, and the portal menu grouped into
 * sections in a fixed order.
 */
class StudentDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_management_sees_every_pupil_of_the_year_with_guardians_and_teachers_do_not(): void
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->firstOrFail()->tenant;
        $year = $tenant->academicYears()->create(['name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30', 'is_current' => true]);
        $a = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => '1ა']);
        $b = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => '2ა']);
        $ana = $tenant->students()->create(['school_class_id' => $a->id, 'first_name' => 'ანა', 'last_name' => 'ტესტი', 'is_active' => true]);
        $tenant->students()->create(['school_class_id' => $b->id, 'first_name' => 'ბექა', 'last_name' => 'ტესტი', 'is_active' => true]);
        $tenant->students()->create(['school_class_id' => $b->id, 'first_name' => 'წასული', 'last_name' => 'ტესტი', 'is_active' => false]);

        [$admin, $teacher, $parent] = array_map(function (string $role) use ($tenant) {
            $user = User::factory()->create();
            TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role, 'is_active' => true]);

            return $user;
        }, [TenantMembership::ROLE_ACADEMIC_MANAGER, TenantMembership::ROLE_TEACHER, TenantMembership::ROLE_GUARDIAN]);
        $link = new GuardianLink(['user_id' => $parent->id, 'student_id' => $ana->id, 'can_view_academic' => true, 'can_view_financial' => true, 'can_pickup' => false, 'can_receive_notifications' => true, 'is_active' => true]);
        $link->tenant_id = $tenant->id;
        $link->save();

        $this->actingAs($admin)->get('/portal/students')->assertOk()->assertInertia(fn ($page) => $page
            ->component('portal/students/index')->where('total', 2)->has('students', 2)
            ->where('students.0.name', 'ანა ტესტი')->where('students.0.guardians.0', $parent->name));
        $this->actingAs($admin)->get("/portal/students?class={$b->id}&inactive=1")->assertOk()->assertInertia(fn ($page) => $page->has('students', 2));
        $this->actingAs($admin)->get('/portal/students?q=ბექა')->assertOk()->assertInertia(fn ($page) => $page->has('students', 1));

        // The menu comes in sections, "მოსწავლეები" among the pupils/families ones.
        $this->actingAs($admin)->get('/portal/students')->assertInertia(fn ($page) => $page
            ->where('portal.navItems.0.key', 'dashboard')
            ->where('portal.navItems.0.groupLabel', null)
            ->where('portal.navItems', fn ($items) => collect($items)->firstWhere('key', 'students')['groupLabel'] === 'მოსწავლეები და ოჯახები'));

        $this->actingAs($teacher)->get('/portal/students')->assertForbidden();
    }
}
