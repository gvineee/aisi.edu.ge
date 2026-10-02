<?php

namespace Tests\Feature\Health;

use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Governance\Models\AuditEvent;
use App\Domain\Health\Models\CarePlanDocument;
use App\Domain\Health\Models\HealthAccessEvent;
use App\Domain\Health\Models\HealthEmergencyAccess;
use App\Domain\Health\Models\HealthPermissionGrant;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * CLAUDE-PLATFORM-MODULES §8/§17: health access comes only from explicit
 * grants (+2FA), an active guardian link, a reasoned time-bound emergency
 * view, or alerts-only for the student's teachers; sensitive fields are
 * encrypted at rest; every read is logged without health content; tenant
 * isolation and download re-checks.
 */
class HealthRecordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /**
     * @return array<string, mixed>
     */
    private function fixtures(): array
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;
        $year = $tenant->academicYears()->create(['name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-15', 'is_current' => true]);
        $class = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => 'VI']);
        $otherClass = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => 'VII']);

        $u = [];
        foreach ([
            'director' => TenantMembership::ROLE_DIRECTOR,
            'admin' => TenantMembership::ROLE_ADMIN,
            'nurse' => TenantMembership::ROLE_ACADEMIC_MANAGER,
            'teacher' => TenantMembership::ROLE_TEACHER,
            'otherTeacher' => TenantMembership::ROLE_TEACHER,
            'guardian' => TenantMembership::ROLE_GUARDIAN,
        ] as $key => $role) {
            $u[$key] = User::factory()->create();
            TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $u[$key]->id, 'role' => $role, 'is_active' => true]);
        }

        // Health staff have 2FA confirmed; nobody else does.
        foreach (['director', 'nurse'] as $key) {
            $u[$key]->forceFill(['two_factor_confirmed_at' => now()])->save();
        }

        $tenant->teacherAssignments()->create(['user_id' => $u['teacher']->id, 'school_class_id' => $class->id, 'subject' => 'მათემატიკა']);
        $tenant->teacherAssignments()->create(['user_id' => $u['otherTeacher']->id, 'school_class_id' => $otherClass->id, 'subject' => 'ისტორია']);

        $student = $tenant->students()->create(['school_class_id' => $class->id, 'first_name' => 'ნიკა', 'last_name' => 'დ.', 'is_active' => true]);

        $link = new GuardianLink([
            'user_id' => $u['guardian']->id, 'student_id' => $student->id, 'can_view_academic' => false,
            'can_view_financial' => false, 'can_pickup' => true, 'can_receive_notifications' => true, 'is_active' => true,
        ]);
        $link->tenant_id = $tenant->id;
        $link->save();

        foreach ([HealthPermissionGrant::VIEW, HealthPermissionGrant::MANAGE] as $capability) {
            $this->actingAs($u['director'])->post('/portal/health/permissions', ['user_id' => $u['nurse']->id, 'capability' => $capability])->assertSessionHasNoErrors();
        }

        return $u + ['tenant' => $tenant, 'student' => $student, 'base' => "/portal/students/{$student->id}/health"];
    }

    /**
     * @param  array<string, mixed>  $f
     */
    private function seedRecord(array $f): void
    {
        $this->actingAs($f['nurse'])->post("{$f['base']}/conditions", [
            'name' => 'თხილზე ალერგია', 'severity' => 'high', 'action_plan' => 'ადრენალინის ავტოინჟექტორი ჩანთაში',
            'show_alert_to_staff' => true, 'staff_alert' => 'თხილი აკრძალულია — სამოქმედო გეგმა ექთანთან',
        ])->assertSessionHasNoErrors();
        $this->actingAs($f['nurse'])->post("{$f['base']}/medications", ['name' => 'ანტიჰისტამინი', 'instructions' => 'მშობლის მიერ მიწოდებული ინსტრუქციით'])->assertSessionHasNoErrors();
        $this->actingAs($f['nurse'])->post("{$f['base']}/contacts", ['name' => 'ია თოდუა', 'relationship' => 'დედა', 'phone' => '+995 555 12 34 20', 'priority' => 1])->assertSessionHasNoErrors();
    }

    public function test_health_staff_with_grant_and_mfa_see_and_manage_full_record_encrypted_at_rest(): void
    {
        $f = $this->fixtures();
        $this->seedRecord($f);

        $this->actingAs($f['nurse'])->get($f['base'])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('portal/health/show')
                ->where('basis', 'full')
                ->where('record.conditions.0.name', 'თხილზე ალერგია')
                ->where('record.contacts.0.phone', '+995 555 12 34 20')
                ->where('can.manage', true));

        // Encrypted at rest: the raw columns never contain the plaintext.
        $this->assertStringNotContainsString('თხილ', (string) DB::table('health_conditions')->value('name'));
        $this->assertStringNotContainsString('555', (string) DB::table('emergency_contacts')->value('phone'));

        // Logs never carry the health content.
        $this->assertStringNotContainsString('თხილ', (string) AuditEvent::query()->pluck('meta')->toJson(JSON_UNESCAPED_UNICODE));
        $this->assertTrue(HealthAccessEvent::query()->where('actor_id', $f['nurse']->id)->where('action', 'view_record')->where('access_basis', 'full')->exists());
    }

    public function test_broad_roles_without_a_grant_see_nothing(): void
    {
        $f = $this->fixtures();
        $this->seedRecord($f);

        foreach (['admin', 'director'] as $who) {
            $this->actingAs($f[$who])->get($f['base'])
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('basis', 'none')->where('record', null)->where('alerts', [])->where('can.requestEmergency', true));
        }

        $this->actingAs($f['admin'])->post("{$f['base']}/medications", ['name' => 'x'])->assertForbidden();
    }

    public function test_grant_without_mfa_does_not_open_the_record(): void
    {
        $f = $this->fixtures();
        $this->seedRecord($f);

        $this->actingAs($f['director'])->post('/portal/health/permissions', ['user_id' => $f['admin']->id, 'capability' => HealthPermissionGrant::MANAGE]);

        $this->actingAs($f['admin'])->get($f['base'])
            ->assertInertia(fn (Assert $page) => $page->where('record', null)->where('needsMfa', true));
        $this->actingAs($f['admin'])->post("{$f['base']}/medications", ['name' => 'x'])->assertForbidden();
    }

    public function test_teacher_sees_only_staff_alerts_and_other_teachers_nothing(): void
    {
        $f = $this->fixtures();
        $this->seedRecord($f);

        $this->actingAs($f['teacher'])->get($f['base'])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('basis', 'alerts_only')
                ->where('record', null)
                ->where('alerts.0.text', 'თხილი აკრძალულია — სამოქმედო გეგმა ექთანთან'));

        $this->actingAs($f['teacher'])->get('/portal/health')
            ->assertInertia(fn (Assert $page) => $page->where('alerts.0.studentName', 'ნიკა დ.')->where('students', []));

        $this->assertTrue(HealthAccessEvent::query()->where('actor_id', $f['teacher']->id)->where('access_basis', 'alerts_only')->exists());

        $this->actingAs($f['otherTeacher'])->get($f['base'])
            ->assertInertia(fn (Assert $page) => $page->where('basis', 'none')->where('alerts', []));
    }

    public function test_guardian_sees_full_record_until_link_is_revoked(): void
    {
        $f = $this->fixtures();
        $this->seedRecord($f);

        $this->actingAs($f['guardian'])->get($f['base'])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('basis', 'guardian')->where('record.medications.0.name', 'ანტიჰისტამინი')->where('can.manage', false));

        $this->actingAs($f['guardian'])->post("{$f['base']}/medications", ['name' => 'x'])->assertForbidden();

        GuardianLink::query()->where('user_id', $f['guardian']->id)->update(['is_active' => false]);
        $this->actingAs($f['guardian'])->get($f['base'])->assertForbidden();
    }

    public function test_emergency_access_is_reasoned_time_bound_logged_and_reviewable(): void
    {
        $f = $this->fixtures();
        $this->seedRecord($f);

        $this->actingAs($f['teacher'])->post("{$f['base']}/emergency-access", ['reason' => 'სწრაფად'])->assertSessionHasErrors('reason');
        $this->actingAs($f['teacher'])->post("{$f['base']}/emergency-access", ['reason' => 'ექსკურსიაზე ალერგიული რეაქციის ნიშნები'])->assertSessionHasNoErrors();

        $this->actingAs($f['teacher'])->get($f['base'])
            ->assertInertia(fn (Assert $page) => $page->where('basis', 'emergency')->where('record.contacts.0.name', 'ია თოდუა'));

        $emergency = HealthEmergencyAccess::query()->firstOrFail();
        $this->assertTrue(HealthAccessEvent::query()->where('emergency_access_id', $emergency->id)->where('action', 'view_record')->exists());
        $this->assertStringNotContainsString('ალერგიული', (string) DB::table('health_emergency_accesses')->value('reason'));

        // Expires by itself.
        $this->travel((int) config('health.emergency_access_minutes') + 1)->minutes();
        $this->actingAs($f['teacher'])->get($f['base'])->assertInertia(fn (Assert $page) => $page->where('basis', 'alerts_only')->where('record', null));

        // Review needs health.audit.view + 2FA, and not by the same person.
        $this->actingAs($f['nurse'])->post("/portal/health/emergency-accesses/{$emergency->id}/review")->assertForbidden();
        $this->actingAs($f['director'])->post('/portal/health/permissions', ['user_id' => $f['nurse']->id, 'capability' => HealthPermissionGrant::AUDIT_VIEW]);
        $this->actingAs($f['nurse'])->get('/portal/health')->assertInertia(fn (Assert $page) => $page->where('reviewQueue.0.id', $emergency->id));
        $this->actingAs($f['nurse'])->post("/portal/health/emergency-accesses/{$emergency->id}/review", ['note' => 'გამართლებული'])->assertSessionHasNoErrors();
        $this->assertNotNull($emergency->fresh()->reviewed_at);

        $this->actingAs($f['nurse'])->get("{$f['base']}/access-log")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('events'));
    }

    public function test_care_plan_download_rechecks_access_and_expires(): void
    {
        $f = $this->fixtures();

        $this->actingAs($f['nurse'])->post("{$f['base']}/care-plans", [
            'title' => 'სკრიპტი', 'file' => UploadedFile::fake()->create('x.html', 5, 'text/html'),
        ])->assertSessionHasErrors('file');

        $this->actingAs($f['nurse'])->post("{$f['base']}/care-plans", [
            'title' => 'სამოქმედო გეგმა', 'file' => UploadedFile::fake()->create('plan.pdf', 50, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $document = CarePlanDocument::query()->firstOrFail();
        // Random file name only — no student id or condition in the path.
        $this->assertMatchesRegularExpression('#^health/\d+/[0-9a-f]{32}\.pdf$#', $document->storage_path);
        Storage::disk('local')->assertExists($document->storage_path);

        $url = URL::temporarySignedRoute('health.care-plans.download', now()->addMinutes(5), [$f['student']->id, $document->id]);

        $this->actingAs($f['nurse'])->get($url)->assertOk();
        $this->actingAs($f['guardian'])->get($url)->assertOk();
        // A valid signed link is not enough without access.
        $this->actingAs($f['teacher'])->get($url)->assertForbidden();

        $this->travel(6)->minutes();
        $this->actingAs($f['nurse'])->get($url)->assertForbidden();

        $this->assertSame(2, HealthAccessEvent::query()->where('action', 'download_care_plan')->count());
    }

    public function test_incident_capability_records_incidents_without_managing(): void
    {
        $f = $this->fixtures();
        $f['teacher']->forceFill(['two_factor_confirmed_at' => now()])->save();
        $this->actingAs($f['director'])->post('/portal/health/permissions', ['user_id' => $f['teacher']->id, 'capability' => HealthPermissionGrant::INCIDENT_CREATE]);

        $this->actingAs($f['teacher'])->post("{$f['base']}/incidents", [
            'occurred_at' => now('Asia/Tbilisi')->subHour()->format('Y-m-d\TH:i'),
            'summary' => 'მსუბუქი ნაკაწრი სპორტზე',
            'action_taken' => 'დამუშავდა ადგილზე',
        ])->assertSessionHasNoErrors();

        $this->actingAs($f['teacher'])->post("{$f['base']}/medications", ['name' => 'x'])->assertForbidden();
        $this->actingAs($f['otherTeacher'])->post("{$f['base']}/incidents", ['occurred_at' => now()->format('Y-m-d\TH:i'), 'summary' => 'x'])->assertForbidden();

        $this->actingAs($f['nurse'])->get($f['base'])->assertInertia(fn (Assert $page) => $page->where('record.incidents.0.summary', 'მსუბუქი ნაკაწრი სპორტზე'));
    }

    public function test_grants_are_managed_by_director_or_admin_only_and_audited(): void
    {
        $f = $this->fixtures();

        $this->actingAs($f['nurse'])->post('/portal/health/permissions', ['user_id' => $f['teacher']->id, 'capability' => HealthPermissionGrant::VIEW])->assertForbidden();
        $this->actingAs($f['director'])->post('/portal/health/permissions', ['user_id' => $f['guardian']->id, 'capability' => HealthPermissionGrant::VIEW])->assertSessionHasErrors('user_id');

        $grant = HealthPermissionGrant::query()->where('user_id', $f['nurse']->id)->where('capability', HealthPermissionGrant::VIEW)->firstOrFail();
        $this->actingAs($f['admin'])->post("/portal/health/permissions/{$grant->id}/revoke")->assertRedirect();
        $this->assertNotNull($grant->fresh()->revoked_at);
        $this->assertTrue(AuditEvent::query()->where('action', 'health_grant.revoked')->exists());
    }

    public function test_tenant_isolation(): void
    {
        $f = $this->fixtures();
        $this->seedRecord($f);
        $this->actingAs($f['nurse'])->post("{$f['base']}/care-plans", ['title' => 'გეგმა', 'file' => UploadedFile::fake()->create('plan.pdf', 50, 'application/pdf')]);
        $document = CarePlanDocument::query()->firstOrFail();

        $otherTenant = Tenant::create(['slug' => 'other-school', 'name' => 'Other', 'locale' => 'ka', 'timezone' => 'Asia/Tbilisi', 'is_active' => true]);
        TenantDomain::create(['tenant_id' => $otherTenant->id, 'domain' => 'other-school.test', 'is_primary' => true]);
        $outsider = User::factory()->create();
        $outsider->forceFill(['two_factor_confirmed_at' => now()])->save();
        TenantMembership::create(['tenant_id' => $otherTenant->id, 'user_id' => $outsider->id, 'role' => TenantMembership::ROLE_DIRECTOR, 'is_active' => true]);
        $grant = new HealthPermissionGrant(['user_id' => $outsider->id, 'capability' => HealthPermissionGrant::MANAGE, 'granted_by' => $outsider->id]);
        $grant->tenant_id = $otherTenant->id;
        $grant->save();

        $host = ['Host' => 'other-school.test'];
        $base = "http://other-school.test{$f['base']}";

        $this->actingAs($outsider)->get($base, $host)->assertNotFound();
        $this->actingAs($outsider)->post("{$base}/medications", ['name' => 'x'], $host)->assertNotFound();
        $this->actingAs($outsider)->post("{$base}/emergency-access", ['reason' => 'სხვა სკოლიდან ცდა'], $host)->assertNotFound();

        $nurseGrant = HealthPermissionGrant::query()->where('tenant_id', $f['tenant']->id)->firstOrFail();
        $this->actingAs($outsider)->post("http://other-school.test/portal/health/permissions/{$nurseGrant->id}/revoke", [], $host)->assertNotFound();

        $url = URL::temporarySignedRoute('health.care-plans.download', now()->addMinutes(5), [$f['student']->id, $document->id]);
        $status = $this->actingAs($outsider)->get(str_replace('http://localhost', 'http://other-school.test', $url), $host)->getStatusCode();
        $this->assertContains($status, [403, 404]);

        $this->assertNull($nurseGrant->fresh()->revoked_at);
    }
}
