<?php

namespace Tests\Feature\Operations;

use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Academics\Models\Student;
use App\Domain\Operations\Models\Club;
use App\Domain\Operations\Models\ClubEnrollment;
use App\Domain\Operations\Models\ClubWaitlistEntry;
use App\Domain\Operations\Models\MealMenu;
use App\Domain\Operations\Models\MealSelection;
use App\Domain\Operations\Models\TransportRoute;
use App\Domain\Operations\Models\TransportStop;
use App\Domain\Tenancy\Models\FeatureEntitlement;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * CLAUDE-PLATFORM-MODULES §11/§17: each operations module is closed on the
 * server until its feature is enabled; clubs keep a hard capacity with an
 * ordered waitlist and atomic promotion; transport shows a guardian only
 * their own child's route; meals publish versioned menus, keep allergen
 * codes only, and enforce the opt-out cutoff.
 */
class OperationsModulesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function fixtures(bool $enable = true): array
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;

        if ($enable) {
            foreach ([FeatureEntitlement::FEATURE_CLUBS, FeatureEntitlement::FEATURE_TRANSPORT, FeatureEntitlement::FEATURE_MEALS] as $feature) {
                $this->artisan('tenant:feature', ['tenant' => $tenant->slug, 'feature' => $feature, 'state' => 'on'])->assertSuccessful();
            }
        }

        $year = $tenant->academicYears()->create(['name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-15', 'is_current' => true]);
        $class = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => 'VI']);

        $u = [];
        foreach (['manager' => TenantMembership::ROLE_ACADEMIC_MANAGER, 'g1' => TenantMembership::ROLE_GUARDIAN, 'g2' => TenantMembership::ROLE_GUARDIAN, 'g3' => TenantMembership::ROLE_GUARDIAN] as $key => $role) {
            $u[$key] = User::factory()->create();
            TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $u[$key]->id, 'role' => $role, 'is_active' => true]);
        }

        $kids = [];
        foreach (['g1' => 'ნიკა', 'g2' => 'ანა', 'g3' => 'ლუკა'] as $guardian => $name) {
            $kids[$guardian] = $tenant->students()->create(['school_class_id' => $class->id, 'first_name' => $name, 'last_name' => 'ტ.', 'is_active' => true]);
            $link = new GuardianLink(['user_id' => $u[$guardian]->id, 'student_id' => $kids[$guardian]->id, 'can_view_academic' => true, 'can_view_financial' => false, 'can_pickup' => true, 'can_receive_notifications' => true, 'is_active' => true]);
            $link->tenant_id = $tenant->id;
            $link->save();
        }

        return $u + ['tenant' => $tenant, 'kids' => $kids];
    }

    private function createClub(User $manager, int $capacity): Club
    {
        $this->actingAs($manager)->post('/portal/clubs', [
            'name' => 'რობოტიკა', 'capacity' => $capacity, 'fee' => '45.50',
            'sessions' => [['day_of_week' => 2, 'starts_at' => '15:00', 'ends_at' => '16:00', 'location' => 'ლაბი']],
        ])->assertSessionHasNoErrors();

        return Club::query()->latest('id')->firstOrFail();
    }

    public function test_disabled_features_are_closed_on_the_server_and_hidden_in_nav(): void
    {
        $f = $this->fixtures(enable: false);

        foreach (['/portal/clubs', '/portal/transport', '/portal/meals'] as $url) {
            $this->actingAs($f['manager'])->get($url)->assertNotFound();
        }
        $this->actingAs($f['manager'])->post('/portal/clubs', ['name' => 'x', 'capacity' => 1])->assertNotFound();

        $this->actingAs($f['g1'])->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('portal.navItems', fn ($items) => ! collect($items)->contains('key', 'services')));

        $this->artisan('tenant:feature', ['tenant' => $f['tenant']->slug, 'feature' => 'clubs', 'state' => 'on'])->assertSuccessful();
        $this->actingAs($f['manager'])->get('/portal/clubs')->assertOk();
        $this->actingAs($f['manager'])->get('/portal/meals')->assertNotFound();
    }

    public function test_club_capacity_waitlist_order_and_atomic_promotion(): void
    {
        $f = $this->fixtures();
        $club = $this->createClub($f['manager'], 1);
        $this->assertSame(4550, $club->fee_minor);

        $this->actingAs($f['g1'])->post("/portal/clubs/{$club->id}/join", ['student_id' => $f['kids']['g1']->id])->assertSessionHas('clubResult', 'enrolled');
        $this->actingAs($f['g2'])->post("/portal/clubs/{$club->id}/join", ['student_id' => $f['kids']['g2']->id])->assertSessionHas('clubResult', 'waitlisted');
        $this->actingAs($f['g3'])->post("/portal/clubs/{$club->id}/join", ['student_id' => $f['kids']['g3']->id])->assertSessionHas('clubResult', 'waitlisted');

        // Never over capacity, and no duplicate join.
        $this->assertSame(1, ClubEnrollment::query()->where('status', 'enrolled')->count());
        $this->actingAs($f['g2'])->post("/portal/clubs/{$club->id}/join", ['student_id' => $f['kids']['g2']->id])->assertSessionHasErrors('club');

        // A guardian cannot act for someone else's child.
        $this->actingAs($f['g1'])->post("/portal/clubs/{$club->id}/join", ['student_id' => $f['kids']['g2']->id])->assertForbidden();

        // Cancellation promotes the first in line in the same transaction.
        $this->actingAs($f['g1'])->post("/portal/clubs/{$club->id}/leave", ['student_id' => $f['kids']['g1']->id])->assertRedirect();
        $this->assertSame([$f['kids']['g2']->id], ClubEnrollment::query()->where('status', 'enrolled')->pluck('student_id')->all());
        $this->assertSame('promoted', ClubWaitlistEntry::query()->where('student_id', $f['kids']['g2']->id)->value('status'));

        // A returning student cannot jump the queue while someone waits.
        $this->actingAs($f['g1'])->post("/portal/clubs/{$club->id}/join", ['student_id' => $f['kids']['g1']->id])->assertSessionHas('clubResult', 'waitlisted');

        // Raising capacity promotes in order (g3 before g1).
        $this->actingAs($f['manager'])->post("/portal/clubs/{$club->id}/capacity", ['capacity' => 2])->assertRedirect();
        $this->assertEqualsCanonicalizing([$f['kids']['g2']->id, $f['kids']['g3']->id], ClubEnrollment::query()->where('status', 'enrolled')->pluck('student_id')->all());

        // Cannot shrink below the enrolled count.
        $this->actingAs($f['manager'])->post("/portal/clubs/{$club->id}/capacity", ['capacity' => 1])->assertSessionHasErrors('capacity');

        $this->actingAs($f['g1'])->get("/portal/clubs/{$club->id}/roster")->assertForbidden();
        $this->actingAs($f['manager'])->get("/portal/clubs/{$club->id}/roster")
            ->assertInertia(fn (Assert $page) => $page->has('enrolled', 2)->where('waitlist.0.studentName', 'ნიკა ტ.'));
    }

    public function test_transport_guardian_sees_only_own_childs_route_and_no_gps(): void
    {
        $f = $this->fixtures();

        $this->actingAs($f['manager'])->post('/portal/transport/routes', ['name' => 'მარშრუტი 03', 'direction' => 'afternoon'])->assertSessionHasNoErrors();
        $this->actingAs($f['manager'])->post('/portal/transport/routes', ['name' => 'მარშრუტი 07', 'direction' => 'morning']);
        [$route, $otherRoute] = TransportRoute::query()->orderBy('id')->get()->all();

        $this->actingAs($f['manager'])->post("/portal/transport/routes/{$route->id}/stops", ['name' => 'დიღომი', 'planned_time' => '16:32'])->assertSessionHasNoErrors();
        $this->actingAs($f['manager'])->post("/portal/transport/routes/{$otherRoute->id}/stops", ['name' => 'ვაკე', 'planned_time' => '08:10']);
        $stop = TransportStop::query()->where('transport_route_id', $route->id)->firstOrFail();
        $otherStop = TransportStop::query()->where('transport_route_id', $otherRoute->id)->firstOrFail();

        // A stop from another route is rejected.
        $this->actingAs($f['manager'])->post("/portal/transport/routes/{$route->id}/assign", ['student_id' => $f['kids']['g1']->id, 'transport_stop_id' => $otherStop->id])->assertSessionHasErrors('transport_stop_id');
        $this->actingAs($f['manager'])->post("/portal/transport/routes/{$route->id}/assign", ['student_id' => $f['kids']['g1']->id, 'transport_stop_id' => $stop->id])->assertSessionHasNoErrors();
        $this->actingAs($f['manager'])->post("/portal/transport/routes/{$route->id}/status", ['status' => 'delayed', 'note' => '10 წუთით'])->assertSessionHasNoErrors();

        $this->actingAs($f['g1'])->get('/portal/transport')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('routes', 1)
                ->where('routes.0.name', 'მარშრუტი 03')
                ->where('routes.0.todayStatus', 'delayed')
                ->where('children.0.assignments.0.stopName', 'დიღომი'));

        $this->actingAs($f['g2'])->get('/portal/transport')->assertInertia(fn (Assert $page) => $page->has('routes', 0));
        $this->actingAs($f['g1'])->post("/portal/transport/routes/{$route->id}/status", ['status' => 'completed'])->assertForbidden();
    }

    public function test_meals_versioning_allergen_projection_and_opt_out_cutoff(): void
    {
        $f = $this->fixtures();
        $day = Carbon::now('Asia/Tbilisi')->addDays(2)->toDateString();

        $this->actingAs($f['manager'])->post('/portal/meals/menus', ['served_on' => $day, 'items' => [
            ['course' => 'სუპი', 'name' => 'ბოსტნეულის სუპი', 'allergens' => ['celery']],
            ['course' => 'მთავარი', 'name' => 'ქათამი ბრინჯით', 'allergens' => []],
        ]])->assertSessionHasNoErrors();
        $this->actingAs($f['manager'])->post('/portal/meals/menus', ['served_on' => $day, 'items' => [['course' => 'x', 'name' => 'y', 'allergens' => ['unknown']]]])
            ->assertSessionHasErrors('items.0.allergens');

        $v1 = MealMenu::query()->firstOrFail();

        // Families do not see drafts.
        $this->actingAs($f['g1'])->get('/portal/meals?week='.$day)->assertInertia(fn (Assert $page) => $page->where('days', fn ($days) => collect($days)->every(fn ($d) => $d['published'] === null)));

        $this->actingAs($f['manager'])->post("/portal/meals/menus/{$v1->id}/publish")->assertSessionHasNoErrors();

        // A change is a new version; publishing it supersedes v1.
        $this->actingAs($f['manager'])->post('/portal/meals/menus', ['served_on' => $day, 'items' => [['course' => 'მთავარი', 'name' => 'თევზი', 'allergens' => ['fish']]]]);
        $v2 = MealMenu::query()->where('version', 2)->firstOrFail();
        $this->actingAs($f['manager'])->post("/portal/meals/menus/{$v2->id}/publish")->assertSessionHasNoErrors();
        $this->assertSame('superseded', $v1->fresh()->status);

        // Guardian sets allergen codes only; unknown codes are dropped.
        $this->actingAs($f['g1'])->post("/portal/meals/students/{$f['kids']['g1']->id}/dietary", ['allergens' => ['fish', 'nonsense']])->assertRedirect();
        $this->actingAs($f['g1'])->post("/portal/meals/students/{$f['kids']['g2']->id}/dietary", ['allergens' => ['milk']])->assertForbidden();

        $this->actingAs($f['g1'])->get('/portal/meals?week='.$day)
            ->assertInertia(fn (Assert $page) => $page
                ->where('children.0.allergens', ['fish'])
                ->where('days', fn ($days) => collect($days)->contains(fn ($d) => $d['date'] === $day && $d['published']['version'] === 2)));

        $this->actingAs($f['manager'])->get('/portal/meals?week='.$day)
            ->assertInertia(fn (Assert $page) => $page->where('kitchen.allergenList.0.allergens', ['fish']));

        // Opt-out before cutoff works and can be undone; after cutoff it is rejected.
        $this->actingAs($f['g1'])->post("/portal/meals/students/{$f['kids']['g1']->id}/opt-out", ['served_on' => $day, 'opt_out' => true])->assertSessionHasNoErrors();
        $this->assertSame(1, MealSelection::query()->count());
        $this->actingAs($f['g1'])->post("/portal/meals/students/{$f['kids']['g1']->id}/opt-out", ['served_on' => $day, 'opt_out' => false])->assertSessionHasNoErrors();
        $this->assertSame(0, MealSelection::query()->count());

        $this->travelTo(Carbon::parse($day.' 09:30', 'Asia/Tbilisi'));
        $this->actingAs($f['g1'])->post("/portal/meals/students/{$f['kids']['g1']->id}/opt-out", ['served_on' => $day, 'opt_out' => true])->assertSessionHasErrors('served_on');
    }

    public function test_tenant_isolation(): void
    {
        $f = $this->fixtures();
        $club = $this->createClub($f['manager'], 5);

        $other = Tenant::create(['slug' => 'other-school', 'name' => 'Other', 'locale' => 'ka', 'timezone' => 'Asia/Tbilisi', 'is_active' => true]);
        TenantDomain::create(['tenant_id' => $other->id, 'domain' => 'other-school.test', 'is_primary' => true]);
        FeatureEntitlement::create(['tenant_id' => $other->id, 'feature' => 'clubs', 'is_enabled' => true]);
        $outsider = User::factory()->create();
        TenantMembership::create(['tenant_id' => $other->id, 'user_id' => $outsider->id, 'role' => TenantMembership::ROLE_DIRECTOR, 'is_active' => true]);

        $host = ['Host' => 'other-school.test'];
        $this->actingAs($outsider)->get("http://other-school.test/portal/clubs/{$club->id}/roster", $host)->assertNotFound();
        $this->actingAs($outsider)->post("http://other-school.test/portal/clubs/{$club->id}/capacity", ['capacity' => 1], $host)->assertNotFound();
        $this->actingAs($outsider)->get('http://other-school.test/portal/clubs', $host)->assertInertia(fn (Assert $page) => $page->has('clubs', 0));

        $this->assertInstanceOf(Student::class, $f['kids']['g1']);
    }
}
