<?php

namespace Tests\Feature\Integrations;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\ClassStaffRole;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Integrations\Models\ImportBatch;
use App\Domain\Integrations\Models\ImportIdentityMap;
use App\Domain\Integrations\Models\ImportIssue;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Timetable\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * EduPage importer with synthetic exports only (no real school data):
 * dry-run writes nothing, apply is idempotent, local edits survive a
 * changed source row, nothing merges by name, people link only by exact
 * e-mail, and two tenants never share a mapping.
 */
class EduPageImportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AcademicYear $year;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::query()->where('slug', 'test-tenant')->firstOrFail();
        $this->year = $this->tenant->academicYears()->create(['name' => '2026-2027', 'starts_on' => '2026-09-03', 'ends_on' => '2027-07-01', 'is_current' => true]);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function file(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'edupage');
        file_put_contents($path, $contents);
        $this->files[] = $path;

        return $path;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function import(string $type, string $path, array $options = [], string $tenant = 'test-tenant'): int
    {
        return Artisan::call('edupage:import', ['type' => $type, 'file' => $path, '--tenant' => $tenant, '--force' => true] + $options);
    }

    private function member(string $email, ?Tenant $tenant = null): User
    {
        $user = User::factory()->create(['email' => $email]);
        TenantMembership::create(['tenant_id' => ($tenant ?? $this->tenant)->id, 'user_id' => $user->id, 'role' => TenantMembership::ROLE_TEACHER, 'is_active' => true]);

        return $user;
    }

    private function classesCsv(string $roomFor7a = '201'): string
    {
        // BOM + semicolons, as a spreadsheet export often is.
        return $this->file("\xEF\xBB\xBFსაფეხური;სახელი;მოკლე სახელი;ოთახი;დამრიგებელი;დამრიგებელი 2;მოსწავლეთა რაოდენობა\n"
            ."1;1ა;1ა;101;ტესტ მასწავლებელი ერთი;;12\n"
            ."VII;7ა;7ა;{$roomFor7a};ტესტ მასწავლებელი ორი;ტესტ მასწავლებელი ერთი;16\n");
    }

    private function importStaffAndRooms(): void
    {
        $this->assertSame(0, $this->import('rooms', $this->file("სახელი\n101\n201\n202\n"), ['--apply' => true]));
        $this->assertSame(0, $this->import('staff', $this->file("სახელი,გვარი,ელფოსტა\nტესტ მასწავლებელი,ერთი,one@example.test\nტესტ მასწავლებელი,ორი,two@example.test\n"), ['--apply' => true]));
    }

    public function test_a_dry_run_writes_nothing_but_the_preview_batch(): void
    {
        $this->assertSame(0, $this->import('classes', $this->classesCsv(), ['--year' => '2026-2027']));

        $this->assertSame(0, SchoolClass::query()->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(0, ImportIdentityMap::query()->count());

        $batch = ImportBatch::query()->sole();
        $this->assertSame(ImportBatch::MODE_DRY_RUN, $batch->mode);
        $this->assertSame('previewed', $batch->status);
        $this->assertSame(2, $batch->counts['created']);
        $this->assertSame(28, $batch->counts['source_students']);
        $this->assertSame(64, strlen($batch->file_sha256));
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $this->tenant->id, 'action' => 'import.previewed']);
    }

    public function test_apply_builds_classes_with_both_homeroom_teachers_and_is_idempotent(): void
    {
        $one = $this->member('one@example.test');
        $two = $this->member('two@example.test');
        $this->importStaffAndRooms();

        $this->assertSame(0, $this->import('classes', $this->classesCsv(), ['--year' => '2026-2027', '--apply' => true]));

        $class = SchoolClass::query()->where('tenant_id', $this->tenant->id)->where('name', '7ა')->sole();
        $this->assertSame(7, $class->grade_level);
        $this->assertSame($two->id, $class->homeroom_teacher_id);
        $this->assertSame(Room::query()->where('name', '201')->value('id'), $class->home_room_id);
        $this->assertTrue(ClassStaffRole::query()->where('school_class_id', $class->id)->where('user_id', $one->id)->where('role', ClassStaffRole::ROLE_CO_HOMEROOM)->exists());

        // A second run of the same file changes nothing.
        $this->assertSame(0, $this->import('classes', $this->classesCsv(), ['--year' => '2026-2027', '--apply' => true]));
        $last = ImportBatch::query()->where('type', 'classes')->latest('id')->first();
        $this->assertSame(0, $last->counts['created']);
        $this->assertSame(2, $last->counts['unchanged']);
        $this->assertSame(2, SchoolClass::query()->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(1, ClassStaffRole::query()->count());
    }

    public function test_a_changed_source_row_updates_but_a_local_edit_is_kept(): void
    {
        $this->member('one@example.test');
        $this->member('two@example.test');
        $this->importStaffAndRooms();
        $this->import('classes', $this->classesCsv(), ['--year' => '2026-2027', '--apply' => true]);

        // Changed source row, untouched target → updated.
        $this->travel(1)->minutes();
        $this->import('classes', $this->classesCsv('202'), ['--year' => '2026-2027', '--apply' => true]);
        $class = SchoolClass::query()->where('tenant_id', $this->tenant->id)->where('name', '7ა')->sole();
        $this->assertSame(Room::query()->where('name', '202')->value('id'), $class->home_room_id);

        // A person edits the class; the next changed export does not overwrite it.
        $this->travel(1)->minutes();
        $class->update(['short_name' => 'შეცვლილი']);
        $this->travel(1)->minutes();
        $this->import('classes', $this->classesCsv('201'), ['--year' => '2026-2027', '--apply' => true]);

        $class->refresh();
        $this->assertSame('შეცვლილი', $class->short_name);
        $this->assertSame(Room::query()->where('name', '202')->value('id'), $class->home_room_id);
        $this->assertTrue(ImportIssue::query()->where('kind', 'conflict')->where('source_key', '7ა')->exists());
    }

    public function test_nothing_is_merged_by_name_and_unknown_people_are_quarantined(): void
    {
        // A class the school made by hand and a pre-existing account with no matching e-mail.
        $manual = $this->tenant->schoolClasses()->create(['academic_year_id' => $this->year->id, 'name' => '1ა']);
        $this->member('someone-else@example.test');
        $this->import('rooms', $this->file("სახელი\n101\n201\n"), ['--apply' => true]);
        $this->import('staff', $this->file("სახელი,გვარი,ელფოსტა\nტესტ მასწავლებელი,ერთი,\nტესტ მასწავლებელი,ორი,two@example.test\n"), ['--apply' => true]);

        $this->assertSame(0, User::query()->where('email', 'two@example.test')->count(), 'staff import never creates accounts');
        $this->assertSame(2, ImportIssue::query()->where('kind', 'needs_invitation')->count());

        $this->import('classes', $this->classesCsv(), ['--year' => '2026-2027', '--apply' => true]);

        $this->assertSame($manual->id, SchoolClass::query()->where('tenant_id', $this->tenant->id)->where('name', '1ა')->sole()->id);
        $this->assertTrue(ImportIssue::query()->where('kind', 'duplicate')->where('source_key', '1ა')->exists());
        $class = SchoolClass::query()->where('tenant_id', $this->tenant->id)->where('name', '7ა')->sole();
        $this->assertNull($class->homeroom_teacher_id);
        $this->assertTrue(ImportIssue::query()->where('kind', 'unresolved_reference')->where('field', 'homeroom')->exists());
        $this->assertSame(0, ImportIssue::query()->where('message', 'like', '%ტესტ მასწავლებელი%')->count(), 'issues never carry raw names');
    }

    public function test_a_shared_staff_name_is_never_used_to_resolve_a_homeroom_teacher(): void
    {
        $this->member('a@example.test');
        $this->member('b@example.test');
        $this->import('staff', $this->file("სრული სახელი,ელფოსტა\nტესტ მასწავლებელი ორი,a@example.test\nტესტ მასწავლებელი ორი,b@example.test\n"), ['--apply' => true]);
        $this->import('classes', $this->classesCsv(), ['--year' => '2026-2027', '--apply' => true]);

        $this->assertNull(SchoolClass::query()->where('tenant_id', $this->tenant->id)->where('name', '7ა')->sole()->homeroom_teacher_id);
        $this->assertSame(0, ImportIdentityMap::query()->where('entity_type', 'staff_label')->count());
    }

    public function test_a_record_repeated_in_one_file_is_imported_once(): void
    {
        $this->import('subjects', $this->file("სახელი,მოკლე სახელი\nმათემატიკა,მათ\nმათემატიკა,მათ.\n"), ['--apply' => true]);

        $this->assertSame(1, $this->tenant->subjects()->where('name', 'მათემატიკა')->count());
        $this->assertSame('მათ', $this->tenant->subjects()->where('name', 'მათემატიკა')->value('short_name'));
        $this->assertTrue(ImportIssue::query()->where('kind', 'duplicate')->where('row_number', 3)->exists());
    }

    public function test_two_tenants_never_share_mappings_or_accounts(): void
    {
        $other = Tenant::create(['slug' => 'other-school', 'name' => 'Other', 'locale' => 'ka', 'timezone' => 'Asia/Tbilisi', 'is_active' => true]);
        $otherYear = $other->academicYears()->create(['name' => '2026-2027', 'starts_on' => '2026-09-03', 'ends_on' => '2027-07-01', 'is_current' => true]);
        // The address belongs to a member of the other school only.
        $this->member('two@example.test', $other);
        $this->importStaffAndRooms();
        $this->import('classes', $this->classesCsv(), ['--year' => '2026-2027', '--apply' => true]);
        $this->import('classes', $this->classesCsv(), ['--year' => '2026-2027', '--apply' => true], 'other-school');

        $this->assertNull(SchoolClass::query()->where('tenant_id', $this->tenant->id)->where('name', '7ა')->sole()->homeroom_teacher_id);
        $this->assertSame(2, SchoolClass::query()->where('tenant_id', $other->id)->where('academic_year_id', $otherYear->id)->count());
        $this->assertSame(2, SchoolClass::query()->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(0, ImportIdentityMap::query()->where('tenant_id', $other->id)->where('scope_id', $this->year->id)->count());
        $this->assertSame(0, ImportIdentityMap::query()->where('tenant_id', $this->tenant->id)->where('scope_id', $otherYear->id)->count());
    }

    public function test_a_missing_required_column_fails_before_anything_is_written(): void
    {
        $this->assertSame(1, $this->import('classes', $this->file("foo,bar\n1,2\n"), ['--year' => '2026-2027', '--apply' => true]));

        $this->assertSame(0, SchoolClass::query()->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame('failed', ImportBatch::query()->sole()->status);
    }

    public function test_binary_workbooks_and_a_year_of_another_school_are_refused(): void
    {
        $this->assertSame(1, $this->import('rooms', $this->file("PK\x03\x04binary")));
        $this->assertSame(1, $this->import('classes', $this->classesCsv(), ['--year' => 'no-such-year']));
        $this->assertSame(0, ImportBatch::query()->count());
    }

    public function test_the_timetable_summary_is_a_preview_only_and_counts_double_lessons(): void
    {
        $this->tenant->schoolClasses()->create(['academic_year_id' => $this->year->id, 'name' => '1ა']);
        $this->tenant->subjects()->create(['name' => 'მათემატიკა']);

        // A MIME quoted-printable HTML worksheet like EduPage's „XLS“ export:
        // 2 days × 2 periods, one double lesson (colspan 2), one unmapped label.
        $html = '<html><body><table>'
            .'<tr><td></td><td colspan=3D\'2\'>ორშაბათი</td><td colspan=3D\'2\'>სამშაბათი</td></tr>'
            .'<tr><td>კლასი</td><td>1</td><td>2</td><td>1</td><td>2</td></tr>'
            .'<tr><td>1ა</td><td>მათემატიკა</td><td>ხელოვნება</td><td colspan=3D\'2\'>მათემატიკა</td></tr>'
            .'<tr><td>9ზ</td><td></td><td>მათემატიკა</td><td></td><td></td></tr>'
            .'</table></body></html>';
        $file = $this->file("MIME-Version: 1.0\nContent-Transfer-Encoding: quoted-printable\nContent-Type: text/html; charset=\"utf-8\"\n\n".$html);

        $this->assertSame(1, $this->import('timetable-summary', $file, ['--year' => '2026-2027', '--apply' => true]), 'apply is refused');
        $this->assertSame(0, ImportBatch::query()->count());

        $this->assertSame(0, $this->import('timetable-summary', $file, ['--year' => '2026-2027', '--source-version' => '210']));
        $batch = ImportBatch::query()->sole();
        $this->assertSame(['source_version' => '210'], $batch->context);
        $this->assertSame(4, $batch->counts['cells']);
        $this->assertSame(5, $batch->counts['lesson_slots']);
        $this->assertSame(2, $batch->counts['subject_labels']);
        $this->assertTrue(ImportIssue::query()->where('kind', 'unresolved_reference')->where('source_key', '9ზ')->exists());
        $this->assertTrue(ImportIssue::query()->where('kind', 'unmapped')->where('source_key', 'ხელოვნება')->exists());
        $this->assertFalse(ImportIssue::query()->where('kind', 'unmapped')->where('source_key', 'მათემატიკა')->exists());
    }
}
