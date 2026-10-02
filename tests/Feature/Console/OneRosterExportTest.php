<?php

namespace Tests\Feature\Console;

use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Governance\Models\AuditEvent;
use App\Domain\Integrations\OneRosterExporter;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * OneRoster export adapter: stable ids, referential consistency, data
 * minimisation, tenant isolation, dry-run writes nothing, real export is
 * private and audited.
 */
class OneRosterExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_is_consistent_minimal_isolated_and_audited(): void
    {
        Storage::fake('local');
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;
        $year = $tenant->academicYears()->create(['name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-15', 'is_current' => true]);
        $class = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => 'VI']);
        $teacher = User::factory()->create(['name' => 'ნინო ბერიძე']);
        $tenant->teacherAssignments()->create(['user_id' => $teacher->id, 'school_class_id' => $class->id, 'subject' => 'მათემატიკა']);
        $student = $tenant->students()->create(['school_class_id' => $class->id, 'first_name' => 'ნიკა', 'last_name' => 'დ.', 'national_id' => '01001012345', 'is_active' => true]);
        $guardian = User::factory()->create(['email' => 'parent@example.test']);
        $link = new GuardianLink(['user_id' => $guardian->id, 'student_id' => $student->id, 'can_view_academic' => true, 'can_view_financial' => false, 'can_pickup' => false, 'can_receive_notifications' => true, 'is_active' => true]);
        $link->tenant_id = $tenant->id;
        $link->save();

        // Another school's student must never appear.
        $other = Tenant::create(['slug' => 'other-school', 'name' => 'Other', 'locale' => 'ka', 'timezone' => 'Asia/Tbilisi', 'is_active' => true]);
        $other->students()->create(['first_name' => 'სხვა', 'last_name' => 'სკოლა', 'is_active' => true]);

        $result = app(OneRosterExporter::class)->build($tenant);
        $this->assertSame([], $result['issues']);
        $this->assertSame(1, $result['counts']['classes.csv']);
        $this->assertSame(2, $result['counts']['enrollments.csv']);

        $all = json_encode($result['files'], JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('01001012345', (string) $all);
        $this->assertStringNotContainsString('parent@example.test', (string) $all);
        $this->assertStringNotContainsString('სხვა', (string) $all);
        $this->assertStringContainsString("{$tenant->slug}-student-{$student->id}", (string) $all);

        // Stable ids across runs.
        $this->assertSame($result['files']['classes.csv'][1][0], app(OneRosterExporter::class)->build($tenant)['files']['classes.csv'][1][0]);

        $this->artisan('oneroster:export', ['tenant' => $tenant->slug, '--dry-run' => true])->assertSuccessful();
        $this->assertSame([], Storage::disk('local')->allFiles());

        $this->artisan('oneroster:export', ['tenant' => $tenant->slug, '--reason' => 'სატესტო გადატანა'])->assertSuccessful();
        $this->assertCount(8, Storage::disk('local')->allFiles("exports/{$tenant->id}"));
        $this->assertTrue(AuditEvent::query()->where('tenant_id', $tenant->id)->where('action', 'export.oneroster')->exists());
    }
}
