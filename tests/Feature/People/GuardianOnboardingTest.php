<?php

namespace Tests\Feature\People;

use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Tenancy\Models\FeatureEntitlement;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A parent from invitation to their child's pages, with school mail not
 * yet configured: the admin hands over the link, the parent accepts, sees
 * their children, and loses a child when that link is switched off.
 */
class GuardianOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_invite_hand_over_the_link_accept_and_follow_two_children(): void
    {
        config(['mail.default' => 'log']);
        $tenant = TenantDomain::query()->where('domain', 'localhost')->firstOrFail()->tenant;
        FeatureEntitlement::query()->updateOrCreate(['tenant_id' => $tenant->id, 'feature' => FeatureEntitlement::FEATURE_FINANCE], ['is_enabled' => true]);
        $year = $tenant->academicYears()->create(['name' => '2026-2027', 'starts_on' => '2026-09-03', 'ends_on' => '2027-07-01', 'is_current' => true]);
        $a = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => '2ა']);
        $b = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => '5ბ']);
        $ana = $tenant->students()->create(['school_class_id' => $a->id, 'first_name' => 'ანა', 'last_name' => 'ტესტი', 'is_active' => true]);
        $luka = $tenant->students()->create(['school_class_id' => $b->id, 'first_name' => 'ლუკა', 'last_name' => 'ტესტი', 'is_active' => true]);

        $director = User::factory()->create(['two_factor_confirmed_at' => now()]);
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $director->id, 'role' => TenantMembership::ROLE_DIRECTOR, 'is_active' => true]);
        $asDirector = fn () => $this->actingAs($director)->withSession(['auth.password_confirmed_at' => time()]);

        // From the pupils list: the members page opens the form for that pupil.
        $this->actingAs($director)->get('/portal/students')->assertInertia(fn ($page) => $page->where('canInviteGuardians', true));
        $this->actingAs($director)->get("/portal/members?invite_student={$ana->id}")->assertInertia(fn ($page) => $page
            ->where('preselectStudentId', $ana->id)->where('mailEnabled', false)
            ->where('students.0.className', '2ა'));

        $asDirector()->post('/portal/members/invite', [
            'email' => 'parent@example.test', 'role' => 'guardian', 'student_id' => $ana->id,
            'can_view_academic' => 1, 'can_view_financial' => 1, 'can_receive_notifications' => 1,
        ])->assertSessionHas('toast.type', 'warning');

        $url = null;
        $this->actingAs($director)->get('/portal/members')->assertInertia(function ($page) use (&$url) {
            $page->where('pendingInvitations.0.email', 'parent@example.test');
            $url = $page->toArray()['props']['pendingInvitations'][0]['acceptUrl'];
        });
        $this->assertNotNull($url);

        // The parent opens the handed-over link, signed out.
        auth()->logout();
        $path = (string) parse_url((string) $url, PHP_URL_PATH);
        $this->get($path)->assertOk();
        $this->post("{$path}/accept", ['name' => 'მშობელი ტესტი', 'password' => 'correct-horse-battery-staple', 'password_confirmation' => 'correct-horse-battery-staple'])
            ->assertRedirect(route('dashboard'));
        $parent = User::query()->where('email', 'parent@example.test')->firstOrFail();

        $children = fn () => array_column($this->actingAs($parent)->get('/portal/my-timetable')->assertOk()->viewData('page')['props']['children'], 'name');
        $this->assertSame(['ანა ტესტი'], $children());
        $this->actingAs($parent)->get('/portal/finance')->assertOk();
        $this->actingAs($parent)->get('/portal/calendar')->assertOk();

        // A second child is linked on the parent's member row.
        $membership = TenantMembership::query()->where('user_id', $parent->id)->sole();
        $asDirector()->post("/portal/members/{$membership->id}/guardian-links", ['student_id' => $luka->id, 'can_view_academic' => 1])->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing(['ანა ტესტი', 'ლუკა ტესტი'], $children());

        // Switching a link off takes that child away again.
        $link = GuardianLink::query()->where('user_id', $parent->id)->where('student_id', $luka->id)->sole();
        $asDirector()->put("/portal/members/guardian-links/{$link->id}", ['is_active' => 0, 'can_view_academic' => 1])->assertSessionHasNoErrors();
        $this->assertSame(['ანა ტესტი'], $children());
    }
}
