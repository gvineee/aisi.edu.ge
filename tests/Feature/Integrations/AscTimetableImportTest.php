<?php

namespace Tests\Feature\Integrations;

use App\Domain\Academics\Actions\LinkStaffAccount;
use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\StaffMember;
use App\Domain\Academics\Models\StudentGroup;
use App\Domain\Academics\Models\TeacherAssignment;
use App\Domain\Integrations\Models\ImportIssue;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Timetable\Actions\CalculateWorkedHours;
use App\Domain\Timetable\Actions\CheckLessonConflicts;
use App\Domain\Timetable\Models\BellPeriod;
use App\Domain\Timetable\Models\Lesson;
use App\Domain\Timetable\Models\LessonException;
use App\Domain\Timetable\Models\NonTeachingPeriod;
use App\Domain\Timetable\Models\Subject;
use App\Domain\Timetable\Models\TimetableVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * EduPage's complete timetable (`asc-timetable`), with a synthetic file:
 * everything lands in dependency order as a draft version, a rerun is
 * unchanged, a joint lesson is one teaching event, staff stay in the
 * directory until an account is linked by hand, and the worked-hours
 * report follows cancellations, cover and holidays.
 */
class AscTimetableImportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AcademicYear $year;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::query()->where('slug', 'test-tenant')->firstOrFail();
        $this->year = $this->tenant->academicYears()->create(['name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30', 'is_current' => true]);
        $this->path = (string) tempnam(sys_get_temp_dir(), 'asc');
        file_put_contents($this->path, (string) json_encode($this->source()));
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    /**
     * Two classes; Teacher A teaches maths to 1ა (twice a week) and a joint
     * music lesson to 1ა+1ბ; "ახალი" (a vacancy) teaches group 1 of 1ბ.
     *
     * @return array<string, mixed>
     */
    private function source(): array
    {
        return [
            'students_omitted' => 30,
            'periods' => [
                ['id' => '1', 'period' => '1', 'starttime' => '09:30', 'endtime' => '10:15'],
                ['id' => '2', 'period' => '2', 'starttime' => '10:20', 'endtime' => '11:05'],
            ],
            'classrooms' => [['id' => '-1', 'name' => 'ოთახი 1', 'short' => '1']],
            'classes' => [
                ['id' => '-10', 'name' => '1ა', 'short' => '1ა', 'teacherids' => ['-100']],
                ['id' => '-11', 'name' => '1ბ', 'short' => '1ბ', 'teacherids' => []],
            ],
            'subjects' => [
                ['id' => '-20', 'name' => 'მათემატიკა', 'short' => 'მათ'],
                ['id' => '-21', 'name' => 'მუსიკა', 'short' => 'მუსიკა'],
                ['id' => '-22', 'name' => 'ინგლისური', 'short' => 'ინგ'],
                ['id' => '-23', 'name' => 'გამოუყენებელი', 'short' => ''],
            ],
            'teachers' => [
                ['id' => '-100', 'name' => 'მასწავლებელი ა', 'short' => 'მა'],
                ['id' => '-101', 'name' => 'ახალი', 'short' => 'ა'],
            ],
            'groups' => [
                ['id' => '*1', 'name' => 'მთელი კლასი', 'classid' => '-10', 'entireclass' => true],
                ['id' => '*2', 'name' => 'მთელი კლასი', 'classid' => '-11', 'entireclass' => true],
                ['id' => '*3', 'name' => 'ჯგუფი 1', 'classid' => '-11', 'entireclass' => false],
            ],
            'lessons' => [
                ['id' => '*10', 'subjectid' => '-20', 'teacherids' => ['-100'], 'classids' => ['-10'], 'groupids' => ['*1'], 'count' => 2, 'durationperiods' => 1],
                ['id' => '*11', 'subjectid' => '-21', 'teacherids' => ['-100'], 'classids' => ['-10', '-11'], 'groupids' => ['*1', '*2'], 'count' => 1, 'durationperiods' => 1],
                ['id' => '*12', 'subjectid' => '-22', 'teacherids' => ['-101'], 'classids' => ['-11'], 'groupids' => ['*3'], 'count' => 1, 'durationperiods' => 1],
            ],
            'cards' => [
                ['id' => '*100', 'lessonid' => '*10', 'period' => '1', 'days' => '10000', 'classroomids' => ['-1']],
                ['id' => '*101', 'lessonid' => '*10', 'period' => '1', 'days' => '00100', 'classroomids' => []],
                ['id' => '*102', 'lessonid' => '*11', 'period' => '2', 'days' => '10000', 'classroomids' => []],
                ['id' => '*103', 'lessonid' => '*12', 'period' => '1', 'days' => '01000', 'classroomids' => []],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function import(bool $apply, array $options = []): int
    {
        return Artisan::call('edupage:import', ['type' => 'asc-timetable', 'file' => $this->path, '--tenant' => 'test-tenant', '--year' => '2026-2027', '--valid-from' => '2026-09-01', '--force' => true] + ($apply ? ['--apply' => true] : []) + $options);
    }

    private function lessons(): int
    {
        return Lesson::query()->where('tenant_id', $this->tenant->id)->count();
    }

    public function test_the_preview_writes_nothing_and_apply_builds_the_whole_timetable_as_a_draft(): void
    {
        $this->assertSame(0, $this->import(false));
        $this->assertSame(0, $this->lessons());
        $this->assertSame(0, StaffMember::query()->count());

        $this->assertSame(0, $this->import(true));

        $this->assertSame(['09:30:00', '10:20:00'], BellPeriod::query()->where('academic_year_id', $this->year->id)->orderBy('number')->pluck('starts_at')->map(fn ($t) => substr((string) $t, 0, 8))->all());
        $this->assertSame(['ინგლისური', 'მათემატიკა', 'მუსიკა'], Subject::query()->where('tenant_id', $this->tenant->id)->orderBy('name')->pluck('name')->all(), 'only subjects a lesson uses');
        $this->assertSame(['1ა', '1ბ'], SchoolClass::query()->where('academic_year_id', $this->year->id)->orderBy('name')->pluck('name')->all());
        $this->assertSame(['ჯგუფი 1'], StudentGroup::query()->pluck('name')->all(), 'the "entire class" group is not a group');

        $teacher = StaffMember::query()->where('full_name', 'მასწავლებელი ა')->sole();
        $vacancy = StaffMember::query()->where('full_name', 'ახალი')->sole();
        $this->assertNull($teacher->user_id, 'no accounts are created');
        $this->assertTrue($vacancy->is_placeholder);
        $this->assertSame($teacher->id, SchoolClass::query()->where('name', '1ა')->value('homeroom_staff_member_id'));

        $version = TimetableVersion::query()->sole();
        $this->assertSame(TimetableVersion::STATUS_DRAFT, $version->status);
        // 2 maths + joint music for two classes + 1 English = 5 rows.
        $this->assertSame(5, Lesson::query()->where('timetable_version_id', $version->id)->count());
        $this->assertSame(2, Lesson::query()->whereNotNull('joint_key')->distinct()->count('school_class_id'));
        $this->assertSame(1, Lesson::query()->whereNotNull('joint_key')->distinct()->count('joint_key'));
        $this->assertSame('ოთახი 1', Lesson::query()->where('day_of_week', 1)->where('period_number', 1)->sole()->room?->name);
        $this->assertSame(StudentGroup::query()->value('id'), Lesson::query()->where('staff_member_id', $vacancy->id)->sole()->student_group_id);

        // Per class: maths 2 + music 1 (1ა), music 1 + English 1 (1ბ).
        $this->assertSame(5, (int) TeacherAssignment::query()->sum('weekly_hours'));
        $this->assertSame(1, TeacherAssignment::query()->whereNotNull('joint_key')->distinct()->count('joint_key'));
        $applied = ImportIssue::query()->where('import_batch_id', ImportIssue::query()->max('import_batch_id'));
        $this->assertSame(0, (clone $applied)->where('kind', 'conflict')->count(), 'a joint lesson is not a double booking');
        $this->assertSame(1, (clone $applied)->where('kind', 'placeholder')->count());

        // A rerun changes nothing.
        $this->import(true);
        $this->assertSame(5, $this->lessons());
        $this->assertSame(2, StaffMember::query()->count());
    }

    public function test_subjects_typed_by_hand_are_reused_only_when_asked(): void
    {
        $existing = new Subject(['name' => 'მათემატიკა']);
        $existing->tenant_id = $this->tenant->id;
        $existing->save();

        $this->import(false);
        $this->assertSame(1, ImportIssue::query()->where('kind', 'duplicate')->count(), 'not merged by name by default');

        $this->import(true, ['--adopt-same-named' => true]);
        $this->assertSame(2, Lesson::query()->where('subject_id', $existing->id)->count());
    }

    public function test_linking_an_account_brings_the_lessons_into_the_teacher_portal_and_conflict_checks(): void
    {
        $this->import(true);
        $staff = StaffMember::query()->where('full_name', 'მასწავლებელი ა')->sole();
        $user = User::factory()->create();
        TenantMembership::create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'role' => TenantMembership::ROLE_TEACHER, 'is_active' => true]);
        $admin = User::factory()->create();

        $version = TimetableVersion::query()->sole();
        $classId = (int) SchoolClass::query()->where('name', '1ბ')->value('id');
        $this->assertTrue(app(CheckLessonConflicts::class)->hasConflict($this->tenant->id, 1, '09:30:00', '10:15:00', $user->id, null, $classId, null, StudentGroup::query()->value('id'), $version->id) === false);

        app(LinkStaffAccount::class)->link($staff, $user, $admin);

        $this->assertSame(4, Lesson::query()->where('teacher_id', $user->id)->count());
        $this->assertSame($user->id, SchoolClass::query()->where('name', '1ა')->value('homeroom_teacher_id'));
        // Monday period 1 is now this teacher's maths lesson in 1ა.
        $this->assertTrue(app(CheckLessonConflicts::class)->hasConflict($this->tenant->id, 1, '09:30:00', '10:15:00', $user->id, null, $classId, null, StudentGroup::query()->value('id'), $version->id));

        $outsider = User::factory()->create();
        $this->expectException(ValidationException::class);
        app(LinkStaffAccount::class)->link(StaffMember::query()->where('full_name', 'ახალი')->sole(), $outsider, $admin);
    }

    public function test_worked_hours_follow_the_active_timetable_cancellations_cover_and_holidays(): void
    {
        $this->import(true);
        TimetableVersion::query()->update(['status' => TimetableVersion::STATUS_ACTIVE]);
        $staff = StaffMember::query()->where('full_name', 'მასწავლებელი ა')->sole();
        $substitute = User::factory()->create(['name' => 'შემცვლელი']);

        // September 2026: Mon 7th … Mon 28th. Maths Mon+Wed, joint music Mon.
        $from = Carbon::parse('2026-09-07');
        $to = Carbon::parse('2026-09-13');
        $mondayMaths = Lesson::query()->where('staff_member_id', $staff->id)->where('day_of_week', 1)->where('period_number', 1)->sole();
        $wednesdayMaths = Lesson::query()->where('staff_member_id', $staff->id)->where('day_of_week', 3)->sole();

        $row = fn (array $report, string $name) => collect($report['rows'])->firstWhere('name', $name);
        $week = app(CalculateWorkedHours::class)->handle($this->tenant->id, $from, $to);
        $this->assertSame(['planned' => 3, 'taught' => 3, 'minutes' => 135], array_intersect_key($row($week, 'მასწავლებელი ა'), ['planned' => 1, 'taught' => 1, 'minutes' => 1]), 'the joint lesson counts once');

        $this->exception($mondayMaths, '2026-09-07', LessonException::TYPE_CANCELLED);
        $this->exception($wednesdayMaths, '2026-09-09', LessonException::TYPE_SUBSTITUTION, $substitute->id);

        $week = app(CalculateWorkedHours::class)->handle($this->tenant->id, $from, $to);
        $teacher = $row($week, 'მასწავლებელი ა');
        $this->assertSame([3, 1, 1, 1], [$teacher['planned'], $teacher['cancelled'], $teacher['handedOver'], $teacher['taught']]);
        $this->assertSame([1, 1], [$row($week, 'შემცვლელი')['covered'], $row($week, 'შემცვლელი')['taught']]);

        $break = new NonTeachingPeriod(['academic_year_id' => $this->year->id, 'label' => 'არდადეგები', 'starts_on' => '2026-09-07', 'ends_on' => '2026-09-13']);
        $break->tenant_id = $this->tenant->id;
        $break->save();
        $this->assertSame([], app(CalculateWorkedHours::class)->handle($this->tenant->id, $from, $to)['rows']);

        // A draft timetable counts nothing.
        TimetableVersion::query()->update(['status' => TimetableVersion::STATUS_DRAFT]);
        $this->assertSame(0, app(CalculateWorkedHours::class)->handle($this->tenant->id, Carbon::parse('2026-09-14'), Carbon::parse('2026-09-20'))['school_days']);
    }

    public function test_the_staff_and_worked_hours_pages_are_for_staff_roles_only(): void
    {
        $this->import(true);
        $accountant = User::factory()->create();
        TenantMembership::create(['tenant_id' => $this->tenant->id, 'user_id' => $accountant->id, 'role' => TenantMembership::ROLE_ACCOUNTANT, 'is_active' => true]);
        $teacher = User::factory()->create();
        TenantMembership::create(['tenant_id' => $this->tenant->id, 'user_id' => $teacher->id, 'role' => TenantMembership::ROLE_TEACHER, 'is_active' => true]);
        $host = 'http://'.$this->tenant->domains()->value('domain');

        $this->actingAs($accountant)->get("{$host}/portal/staff")->assertOk()
            ->assertInertia(fn ($page) => $page->component('portal/staff/index')->where('canManage', false)->has('staff', 2));
        $this->actingAs($accountant)->get("{$host}/portal/worked-hours?month=2026-09")->assertOk();
        $this->actingAs($accountant)->get("{$host}/portal/worked-hours/export?month=2026-09")->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->actingAs($accountant)->post("{$host}/portal/staff", ['full_name' => 'x'])->assertForbidden();
        $this->actingAs($teacher)->get("{$host}/portal/staff")->assertForbidden();
        $this->actingAs($teacher)->get("{$host}/portal/worked-hours")->assertForbidden();
    }

    private function exception(Lesson $lesson, string $date, string $type, ?int $substituteId = null): void
    {
        $exception = new LessonException(['lesson_id' => $lesson->id, 'occurs_on' => $date, 'type' => $type, 'substitute_teacher_id' => $substituteId]);
        $exception->tenant_id = $this->tenant->id;
        $exception->save();
    }
}
