<?php

namespace Tests\Feature\Platform;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Governance\Models\AuditEvent;
use App\Domain\Operations\Models\Club;
use App\Domain\Platform\DomainVerifier;
use App\Domain\Platform\Models\PlatformAuditEvent;
use App\Domain\Platform\Models\SubscriptionPlan;
use App\Domain\Platform\Models\SupportAccessRequest;
use App\Domain\Platform\Models\TenantSubscription;
use App\Domain\Tenancy\Models\FeatureEntitlement;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantInvitation;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * CLAUDE-PLATFORM-MODULES §14/§17.9: platform operators are separate from
 * tenant roles and need 2FA; new tenants stay dark until their domain is
 * DNS-verified and onboarding is reviewed; wizard steps are idempotent;
 * disabling a feature closes routes without deleting data; support access
 * needs tenant approval, is time-limited, revocable and audited on both
 * sides; plans carry no invented prices.
 */
class PlatformAdministrationTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private Tenant $aisi;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->aisi = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;
        $this->operator = User::factory()->create(['email' => 'ops@platform.test']);
        $this->operator->forceFill(['two_factor_confirmed_at' => now()])->save();
        $this->artisan('platform:operator', ['email' => 'ops@platform.test'])->assertSuccessful();
    }

    private function fakeDns(bool $result): void
    {
        $this->app->instance(DomainVerifier::class, new class($result) extends DomainVerifier
        {
            public function __construct(private readonly bool $result) {}

            public function verify(string $domain, string $token): bool
            {
                return $this->result;
            }
        });
    }

    public function test_only_operators_with_mfa_reach_the_console(): void
    {
        $schoolAdmin = User::factory()->create();
        $schoolAdmin->forceFill(['two_factor_confirmed_at' => now()])->save();
        TenantMembership::create(['tenant_id' => $this->aisi->id, 'user_id' => $schoolAdmin->id, 'role' => TenantMembership::ROLE_ADMIN, 'is_active' => true]);

        $this->actingAs($schoolAdmin)->get('/platform-admin')->assertNotFound();

        $noMfa = User::factory()->create(['email' => 'nomfa@platform.test']);
        $this->artisan('platform:operator', ['email' => 'nomfa@platform.test'])->assertSuccessful();
        $this->actingAs($noMfa)->get('/platform-admin')->assertNotFound();

        $this->actingAs($this->operator)->get('/platform-admin')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $this->artisan('platform:operator', ['email' => 'ops@platform.test', '--revoke' => true])->assertSuccessful();
        $this->actingAs($this->operator)->get('/platform-admin')->assertNotFound();
    }

    public function test_onboarding_wizard_is_idempotent_and_tenant_goes_live_only_when_ready(): void
    {
        $this->actingAs($this->operator)->post('/platform-admin/tenants', ['slug' => 'mze', 'name' => 'მზე', 'domain' => 'mze-school.test'])->assertRedirect();
        $tenant = Tenant::query()->where('slug', 'mze')->firstOrFail();
        $domain = TenantDomain::query()->where('tenant_id', $tenant->id)->firstOrFail();

        $this->assertFalse($tenant->is_active);
        $this->assertNotNull($domain->verification_token);
        $this->assertNull($domain->verified_at);

        // Pending domain and inactive tenant serve nothing; no fallback to another school.
        $this->get('http://mze-school.test/login', ['Host' => 'mze-school.test'])->assertNotFound();

        // Absolute: the request above moved the test client to the other host.
        $base = "http://localhost/platform-admin/tenants/{$tenant->id}";

        // Review is refused while steps are missing.
        $this->actingAs($this->operator)->post("{$base}/onboarding/review")->assertSessionHasErrors('review');

        // Brand: SVG refused, PNG accepted; colours validated.
        $this->actingAs($this->operator)->post("{$base}/onboarding/brand", ['display_name' => 'მზე', 'primary' => '#112233', 'accent' => '#FF6600', 'secondary' => '#F5F5F5',
            'logo' => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml')])->assertSessionHasErrors('logo');
        $this->actingAs($this->operator)->post("{$base}/onboarding/brand", ['display_name' => 'მზე', 'primary' => 'red', 'accent' => '#FF6600', 'secondary' => '#F5F5F5'])->assertSessionHasErrors('primary');
        $this->actingAs($this->operator)->post("{$base}/onboarding/brand", ['display_name' => 'მზე', 'short_name' => 'მზე', 'primary' => '#112233', 'accent' => '#FF6600', 'secondary' => '#F5F5F5',
            'logo' => UploadedFile::fake()->image('logo.png', 300, 100)])->assertSessionHasNoErrors();
        $this->assertNotNull($tenant->brandSetting()->first()->logo_path);

        $this->actingAs($this->operator)->post("{$base}/onboarding/locale", ['locale' => 'ka', 'timezone' => 'Mars/Base'])->assertSessionHasErrors('timezone');
        $this->actingAs($this->operator)->post("{$base}/onboarding/locale", ['locale' => 'ka', 'timezone' => 'Asia/Tbilisi'])->assertSessionHasNoErrors();

        // Re-running a step updates instead of duplicating.
        foreach ([1, 2] as $_) {
            $this->actingAs($this->operator)->post("{$base}/onboarding/academic_year", ['name' => '2026-2027', 'starts_on' => '2026-09-15', 'ends_on' => '2027-06-15'])->assertSessionHasNoErrors();
            $this->actingAs($this->operator)->post("{$base}/onboarding/staff_invites", ['email' => 'director@mze-school.test'])->assertSessionHasNoErrors();
        }
        $this->assertSame(1, AcademicYear::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
        $this->assertSame(1, TenantInvitation::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
        $this->assertSame('admin', TenantInvitation::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->value('role'));

        $this->actingAs($this->operator)->post("{$base}/onboarding/modules", ['features' => ['web', 'school_portal', 'clubs']])->assertSessionHasNoErrors();
        $this->assertTrue(FeatureEntitlement::isEnabled($tenant->id, 'clubs'));
        $this->assertFalse(FeatureEntitlement::isEnabled($tenant->id, 'finance'));

        // Domain not yet proven → still not live.
        $this->fakeDns(false);
        $this->actingAs($this->operator)->post("{$base}/domains/{$domain->id}/verify")->assertRedirect();
        $this->assertNull($domain->fresh()->verified_at);
        $this->actingAs($this->operator)->post("{$base}/onboarding/review")->assertSessionHasErrors('review');

        $this->fakeDns(true);
        $this->actingAs($this->operator)->post("{$base}/domains/{$domain->id}/verify")->assertRedirect();
        $this->assertNotNull($domain->fresh()->verified_at);

        $this->actingAs($this->operator)->post("{$base}/onboarding/review")->assertSessionHasNoErrors();
        $this->assertTrue($tenant->fresh()->is_active);
        // Served now (an authenticated user is redirected away from /login, not 404).
        $this->assertNotSame(404, $this->get('http://mze-school.test/login', ['Host' => 'mze-school.test'])->status());

        $this->assertTrue(PlatformAuditEvent::query()->where('tenant_id', $tenant->id)->where('action', 'tenant.activated')->exists());
    }

    public function test_feature_switch_closes_routes_without_deleting_data_and_plans_have_no_invented_price(): void
    {
        $this->actingAs($this->operator)->post("/platform-admin/tenants/{$this->aisi->id}/features", ['feature' => 'clubs', 'enabled' => true])->assertRedirect();

        $manager = User::factory()->create();
        TenantMembership::create(['tenant_id' => $this->aisi->id, 'user_id' => $manager->id, 'role' => TenantMembership::ROLE_ACADEMIC_MANAGER, 'is_active' => true]);
        $this->actingAs($manager)->post('/portal/clubs', ['name' => 'ჭადრაკი', 'capacity' => 10])->assertSessionHasNoErrors();

        $this->actingAs($this->operator)->post("/platform-admin/tenants/{$this->aisi->id}/features", ['feature' => 'clubs', 'enabled' => false]);
        $this->actingAs($manager)->get('/portal/clubs')->assertNotFound();
        $this->assertSame(1, Club::query()->withoutGlobalScopes()->count());

        $this->actingAs($this->operator)->post('/platform-admin/plans', ['code' => 'basic', 'name' => 'საბაზისო', 'features' => ['web', 'school_portal']])->assertSessionHasNoErrors();
        $plan = SubscriptionPlan::query()->where('code', 'basic')->firstOrFail();
        $this->assertNull($plan->price_minor);
        $this->assertSame(['school_portal', 'web'], $plan->features()->pluck('feature')->sort()->values()->all());

        $this->actingAs($this->operator)->post("/platform-admin/tenants/{$this->aisi->id}/subscription", ['plan_id' => $plan->id, 'status' => 'trial'])->assertSessionHasNoErrors();
        $this->assertSame('trial', TenantSubscription::query()->where('tenant_id', $this->aisi->id)->value('status'));
    }

    public function test_support_access_requires_tenant_approval_is_time_limited_revocable_and_audited(): void
    {
        $schoolAdmin = User::factory()->create();
        TenantMembership::create(['tenant_id' => $this->aisi->id, 'user_id' => $schoolAdmin->id, 'role' => TenantMembership::ROLE_ADMIN, 'is_active' => true]);
        $teacher = User::factory()->create();
        TenantMembership::create(['tenant_id' => $this->aisi->id, 'user_id' => $teacher->id, 'role' => TenantMembership::ROLE_TEACHER, 'is_active' => true]);

        $base = "/platform-admin/tenants/{$this->aisi->id}";

        $this->actingAs($this->operator)->post("{$base}/support-requests", ['reason' => 'მოკლე', 'minutes' => 60])->assertSessionHasErrors('reason');
        $this->actingAs($this->operator)->post("{$base}/support-requests", ['reason' => 'ტაბელების გვერდის შეცდომის დიაგნოსტიკა', 'minutes' => 60])->assertSessionHasNoErrors();
        $request = SupportAccessRequest::query()->firstOrFail();

        // No access before approval.
        $this->actingAs($this->operator)->get("{$base}/support-view")->assertForbidden();

        // Only the school's own admin/director decides.
        $this->actingAs($teacher)->post("/portal/support-access/{$request->id}", ['decision' => 'approve'])->assertForbidden();
        $this->actingAs($schoolAdmin)->get('/portal/support-access')->assertInertia(fn (Assert $page) => $page->where('requests.0.status', 'requested'));
        $this->actingAs($schoolAdmin)->post("/portal/support-access/{$request->id}", ['decision' => 'approve'])->assertRedirect();

        $this->actingAs($this->operator)->get("{$base}/support-view")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('platform/support-view')->has('expiresAt'));

        // Revocation ends it immediately.
        $this->actingAs($schoolAdmin)->post("/portal/support-access/{$request->id}", ['decision' => 'revoke']);
        $this->actingAs($this->operator)->get("{$base}/support-view")->assertForbidden();

        // A second approved session expires on its own.
        $this->actingAs($this->operator)->post("{$base}/support-requests", ['reason' => 'კვლავ დიაგნოსტიკა საჭიროა', 'minutes' => 15]);
        $second = SupportAccessRequest::query()->latest('id')->firstOrFail();
        $this->actingAs($schoolAdmin)->post("/portal/support-access/{$second->id}", ['decision' => 'approve']);
        $this->travel(16)->minutes();
        $this->actingAs($this->operator)->get("{$base}/support-view")->assertForbidden();

        // Audited on both sides.
        $this->assertTrue(PlatformAuditEvent::query()->where('action', 'support.viewed')->exists());
        $this->assertTrue(AuditEvent::query()->where('tenant_id', $this->aisi->id)->where('action', 'support_access.approved')->exists());
        $this->assertTrue(AuditEvent::query()->where('tenant_id', $this->aisi->id)->where('action', 'support_access.revoked')->exists());
    }
}
