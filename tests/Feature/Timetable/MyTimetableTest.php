<?php

namespace Tests\Feature\Timetable;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\Student;
use App\Domain\Academics\Models\StudentGroup;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Timetable\Actions\ResolveDailyLessons;
use App\Domain\Timetable\Models\Lesson;
use App\Domain\Timetable\Models\LessonException;
use App\Domain\Timetable\Models\SubstitutionAssignment;
use App\Domain\Timetable\Models\TimetableVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Personal timetable: a pupil sees only their class and groups, a guardian
 * only authorised children, a teacher own lessons plus that day's covers;
 * versions, drafts, exceptions and tenants never leak or double up.
 * Synthetic data only.
 */
class MyTimetableTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AcademicYear $year;

    private SchoolClass $class;

    private SchoolClass $otherClass;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        // Monday 26 October 2026, school time.
        $this->travelTo(Carbon::parse('2026-10-26 08:00', 'Asia/Tbilisi'));

        $this->tenant = TenantDomain::query()->where('domain', 'localhost')->firstOrFail()->tenant;
        $this->year = $this->tenant->academicYears()->create(['name' => '2026-2027', 'starts_on' => '2026-09-03', 'ends_on' => '2027-07-01', 'is_current' => true]);
        $this->class = $this->tenant->schoolClasses()->create(['academic_year_id' => $this->year->id, 'name' => '7ა']);
        $this->otherClass = $this->tenant->schoolClasses()->create(['academic_year_id' => $this->year->id, 'name' => '8ა']);
        $this->teacher = $this->member(TenantMembership::ROLE_TEACHER);
    }

    private function member(string $role): User
    {
        $user = User::factory()->create();
        TenantMembership::create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'role' => $role, 'is_active' => true]);

        return $user;
    }

    private function pupil(SchoolClass $class, ?User $account = null): Student
    {
        $student = new Student(['school_class_id' => $class->id, 'first_name' => 'მოსწავლე', 'last_name' => 'ტესტი', 'is_active' => true, 'user_id' => $account?->id]);
        $student->tenant_id = $this->tenant->id;
        $student->save();

        return $student;
    }

    private function group(string $name, Student ...$members): StudentGroup
    {
        $group = new StudentGroup(['school_class_id' => $this->class->id, 'name' => $name]);
        $group->tenant_id = $this->tenant->id;
        $group->save();
        $group->students()->attach(collect($members)->mapWithKeys(fn (Student $s) => [$s->id => ['tenant_id' => $this->tenant->id]])->all());

        return $group;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function lesson(string $subject, array $overrides = []): Lesson
    {
        $lesson = new Lesson(array_merge([
            'academic_year_id' => $this->year->id,
            'school_class_id' => $this->class->id,
            'subject_id' => $this->tenant->subjects()->firstOrCreate(['name' => $subject])->id,
            'teacher_id' => $this->teacher->id,
            'day_of_week' => 1,
            'starts_at' => '09:30:00',
            'ends_at' => '10:15:00',
            'status' => Lesson::STATUS_PUBLISHED,
        ], $overrides));
        $lesson->tenant_id = $this->tenant->id;
        $lesson->save();

        return $lesson;
    }

    /**
     * @return list<string>
     */
    private function subjects(User $user, string $query = 'view=day&date=2026-10-26'): array
    {
        $subjects = [];
        $this->actingAs($user)->get("/portal/my-timetable?{$query}")
            ->assertOk()
            ->assertInertia(function ($page) use (&$subjects) {
                foreach ($page->toArray()['props']['days'] as $day) {
                    foreach ($day['lessons'] as $lesson) {
                        $subjects[] = $lesson['subject'];
                    }
                }
            });

        return $subjects;
    }

    public function test_a_pupil_sees_their_class_and_own_group_only(): void
    {
        $account = $this->member(TenantMembership::ROLE_STUDENT);
        $me = $this->pupil($this->class, $account);
        $classmate = $this->pupil($this->class);
        $this->lesson('მათემატიკა');
        $this->lesson('ინგლისური 1', ['student_group_id' => $this->group('ჯგუფი 1', $me)->id, 'starts_at' => '10:20:00', 'ends_at' => '11:05:00']);
        $this->lesson('ინგლისური 2', ['student_group_id' => $this->group('ჯგუფი 2', $classmate)->id, 'starts_at' => '10:20:00', 'ends_at' => '11:05:00']);
        $this->lesson('ფიზიკა', ['school_class_id' => $this->otherClass->id]);
        $this->lesson('ქიმია', ['status' => Lesson::STATUS_DRAFT, 'starts_at' => '12:00:00', 'ends_at' => '12:45:00']);

        $this->assertSame(['მათემატიკა', 'ინგლისური 1'], $this->subjects($account));

        // Ids in the query never widen what a pupil sees.
        $this->assertSame(['მათემატიკა', 'ინგლისური 1'], $this->subjects($account, "view=day&date=2026-10-26&child={$classmate->id}&student={$classmate->id}"));
    }

    public function test_a_guardian_sees_only_authorised_children_and_loses_access_on_revocation(): void
    {
        $guardian = $this->member(TenantMembership::ROLE_GUARDIAN);
        $child = $this->pupil($this->class);
        $stranger = $this->pupil($this->otherClass);
        $this->lesson('მათემატიკა');
        $this->lesson('ფიზიკა', ['school_class_id' => $this->otherClass->id]);
        $link = new GuardianLink(['user_id' => $guardian->id, 'student_id' => $child->id, 'can_view_academic' => true, 'is_active' => true]);
        $link->tenant_id = $this->tenant->id;
        $link->save();

        $this->assertSame(['მათემატიკა'], $this->subjects($guardian));
        // Asking for someone else's child falls back to the guardian's own.
        $this->assertSame(['მათემატიკა'], $this->subjects($guardian, "view=day&date=2026-10-26&child={$stranger->id}"));

        $link->update(['can_view_academic' => false]);
        $this->actingAs($guardian)->get('/portal/my-timetable')->assertInertia(fn ($page) => $page->where('state', 'no_children')->where('children', []));

        $link->update(['can_view_academic' => true, 'is_active' => false]);
        $this->assertSame([], $this->subjects($guardian));
    }

    public function test_a_teacher_sees_own_lessons_and_covers_only_on_the_covered_day(): void
    {
        $colleague = $this->member(TenantMembership::ROLE_TEACHER);
        $mine = $this->lesson('მათემატიკა');
        $theirs = $this->lesson('ისტორია', ['teacher_id' => $colleague->id, 'school_class_id' => $this->otherClass->id, 'starts_at' => '11:10:00', 'ends_at' => '11:55:00']);
        $cancelled = $this->lesson('გეოგრაფია', ['starts_at' => '12:05:00', 'ends_at' => '12:50:00']);

        $exception = new LessonException(['lesson_id' => $cancelled->id, 'occurs_on' => '2026-10-26', 'type' => LessonException::TYPE_CANCELLED]);
        $exception->tenant_id = $this->tenant->id;
        $exception->save();

        // I cover the colleague's lesson today; the colleague covers mine.
        foreach ([[$theirs, $colleague, $this->teacher], [$mine, $this->teacher, $colleague]] as [$lesson, $absent, $substitute]) {
            $cover = new SubstitutionAssignment(['lesson_id' => $lesson->id, 'absent_teacher_id' => $absent->id, 'substitute_teacher_id' => $substitute->id, 'date' => '2026-10-26', 'status' => SubstitutionAssignment::STATUS_ASSIGNED]);
            $cover->tenant_id = $this->tenant->id;
            $cover->save();
        }

        $statuses = [];
        $this->actingAs($this->teacher)->get('/portal/my-timetable?view=day&date=2026-10-26')
            ->assertInertia(function ($page) use (&$statuses) {
                foreach ($page->toArray()['props']['days'][0]['lessons'] as $lesson) {
                    $statuses[$lesson['subject']] = [$lesson['status'], $lesson['openable']];
                }
            });

        $this->assertSame([
            'მათემატიკა' => [ResolveDailyLessons::STATUS_HANDED_OVER, false],
            'ისტორია' => [ResolveDailyLessons::STATUS_COVERING, true],
            'გეოგრაფია' => [ResolveDailyLessons::STATUS_CANCELLED, true],
        ], $statuses);

        // A week later the cover is gone: only the teacher's own lessons.
        $this->assertSame(['მათემატიკა', 'გეოგრაფია'], $this->subjects($this->teacher, 'view=day&date=2026-11-02'));
    }

    public function test_only_the_version_in_effect_counts_and_nothing_doubles_up(): void
    {
        $account = $this->member(TenantMembership::ROLE_STUDENT);
        $this->pupil($this->class, $account);
        $version = function (string $label, string $from, ?string $to, string $status) {
            $v = new TimetableVersion(['academic_year_id' => $this->year->id, 'label' => $label, 'valid_from' => $from, 'valid_to' => $to, 'status' => $status]);
            $v->tenant_id = $this->tenant->id;
            $v->save();

            return $v;
        };
        $old = $version('v209', '2026-09-03', '2026-10-20', TimetableVersion::STATUS_ARCHIVED);
        $current = $version('v210', '2026-10-21', null, TimetableVersion::STATUS_ACTIVE);
        $draft = $version('v211', '2026-10-26', null, TimetableVersion::STATUS_DRAFT);
        $this->lesson('ძველი', ['timetable_version_id' => $old->id]);
        $this->lesson('ახალი', ['timetable_version_id' => $current->id]);
        $this->lesson('სამუშაო', ['timetable_version_id' => $draft->id]);
        $this->lesson('ვერსიის გარეშე');

        $this->assertSame(['ახალი'], $this->subjects($account));
        $this->assertSame(['ძველი'], $this->subjects($account, 'view=day&date=2026-10-12'));
    }

    public function test_the_week_matches_the_daily_resolver_and_is_never_cached(): void
    {
        $account = $this->member(TenantMembership::ROLE_STUDENT);
        $student = $this->pupil($this->class, $account);
        $this->lesson('მათემატიკა');
        $this->lesson('ქართული', ['day_of_week' => 3]);

        $response = $this->actingAs($account)->get('/portal/my-timetable?view=week&date=2026-10-28');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $response->assertInertia(function ($page) use ($student) {
            $days = $page->toArray()['props']['days'];
            // Monday–Friday; the empty weekend is left out.
            $this->assertSame(['2026-10-26', '2026-10-27', '2026-10-28', '2026-10-29', '2026-10-30'], array_column($days, 'date'));

            foreach ($days as $day) {
                $expected = app(ResolveDailyLessons::class)
                    ->forStudent($this->tenant->id, $student, Carbon::parse($day['date'], 'Asia/Tbilisi'))
                    ->map(fn (array $entry) => $entry['lesson']->id)
                    ->all();
                $this->assertSame($expected, array_column($day['lessons'], 'id'));
            }
        });
    }

    public function test_other_roles_tenants_and_dates_outside_the_year(): void
    {
        $admin = $this->member(TenantMembership::ROLE_ACCOUNTANT);
        $this->actingAs($admin)->get('/portal/my-timetable')->assertForbidden();

        $account = $this->member(TenantMembership::ROLE_STUDENT);
        $this->pupil($this->class, $account);
        $this->lesson('მათემატიკა');

        // Another school's lesson on the same weekday never shows.
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'locale' => 'ka', 'timezone' => 'Asia/Tbilisi', 'is_active' => true]);
        $otherYear = $other->academicYears()->create(['name' => '2026-2027', 'starts_on' => '2026-09-03', 'ends_on' => '2027-07-01', 'is_current' => true]);
        $otherClass = $other->schoolClasses()->create(['academic_year_id' => $otherYear->id, 'name' => '7ა']);
        $this->lesson('უცხო', ['academic_year_id' => $otherYear->id, 'school_class_id' => $otherClass->id]);
        Lesson::query()->where('school_class_id', $otherClass->id)->update(['tenant_id' => $other->id]);

        $this->assertSame(['მათემატიკა'], $this->subjects($account));

        $this->actingAs($account)->get('/portal/my-timetable?view=day&date=2026-08-03')
            ->assertInertia(fn ($page) => $page->where('state', 'outside_year'));
        $this->actingAs($account)->get('/portal/my-timetable?date=not-a-date')
            ->assertInertia(fn ($page) => $page->where('date', '2026-10-26'));
    }
}
