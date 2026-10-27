<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Tenancy\Models\FeatureEntitlement;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\PortalContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The roles-and-permissions table is the navigation, and the navigation
 * must agree with the controllers: every module a role is shown opens for
 * that role, and every module it is not shown refuses it.
 */
class PermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Pages anyone signed in may open, listing only their own rows (none
     * for most roles) — the controller scopes the query, not the door.
     *
     * @var list<string>
     */
    private const OWN_ROWS_ONLY = ['absence-notes', 'consents', 'pickup', 'health'];

    public function test_the_matrix_matches_what_each_role_can_open(): void
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->firstOrFail()->tenant;
        $tenant->academicYears()->create(['name' => '2026-2027', 'starts_on' => '2026-09-03', 'ends_on' => '2027-07-01', 'is_current' => true]);

        foreach ([FeatureEntitlement::FEATURE_FINANCE, FeatureEntitlement::FEATURE_LIBRARY, FeatureEntitlement::FEATURE_CLUBS, FeatureEntitlement::FEATURE_TRANSPORT, FeatureEntitlement::FEATURE_MEALS] as $feature) {
            FeatureEntitlement::query()->updateOrCreate(['tenant_id' => $tenant->id, 'feature' => $feature], ['is_enabled' => true]);
        }

        $context = app(PortalContext::class);
        $users = [];

        foreach (array_keys($context->roleLabels()) as $role) {
            $users[$role] = User::factory()->create(['two_factor_confirmed_at' => now()]);
            TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $users[$role]->id, 'role' => $role, 'is_active' => true]);
        }

        // A pupil with an account and a guardian with every right, so the
        // family pages have someone to show.
        $class = $tenant->schoolClasses()->create(['academic_year_id' => $tenant->academicYears()->value('id'), 'name' => '1ა']);
        $child = $tenant->students()->create(['school_class_id' => $class->id, 'first_name' => 'ანა', 'last_name' => 'ტესტი', 'is_active' => true, 'user_id' => $users[TenantMembership::ROLE_STUDENT]->id]);
        $link = new GuardianLink(['user_id' => $users[TenantMembership::ROLE_GUARDIAN]->id, 'student_id' => $child->id, 'can_view_academic' => true, 'can_view_financial' => true, 'can_pickup' => true, 'can_receive_notifications' => true, 'is_active' => true]);
        $link->tenant_id = $tenant->id;
        $link->save();

        $page = $this->actingAs($users[TenantMembership::ROLE_DIRECTOR])->get('/portal/permissions')->assertOk()->viewData('page')['props'];
        $this->assertSame(array_keys($context->roleLabels()), array_column($page['roles'], 'value'));
        $this->assertSame(1, $page['roles'][0]['members']);
        $this->assertTrue(collect($page['roles'])->firstWhere('value', TenantMembership::ROLE_ACCOUNTANT)['mfa']);
        $this->actingAs($users[TenantMembership::ROLE_TEACHER])->get('/portal/permissions')->assertForbidden();

        $features = $context->enabledFeatures($tenant->id);
        $hrefs = [];

        foreach (array_keys($users) as $role) {
            foreach ($context->navItemsFor($role, $features) as $item) {
                $hrefs[$item['key']][$role] = parse_url($item['href'], PHP_URL_PATH);
            }
        }

        $mismatches = [];

        foreach ($page['rows'] as $row) {
            foreach ($row['roles'] as $role => $shown) {
                $path = $hrefs[$row['key']][$role] ?? reset($hrefs[$row['key']]);
                $status = $this->actingAs($users[$role])->withSession([PortalContext::SESSION_KEY => $role])->get($path)->status();
                $opens = $status < 400;

                if ($opens !== $shown && ! ($opens && in_array($row['key'], self::OWN_ROWS_ONLY, true))) {
                    $mismatches[] = "{$row['key']} {$role}: ".($shown ? 'shown' : 'hidden')." but {$path} → {$status}";
                }
            }
        }

        $this->assertSame([], $mismatches);
    }
}
