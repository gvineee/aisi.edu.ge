<?php

namespace Tests\Feature\Timetable;

use App\Domain\Academics\Models\StudentGroup;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Timetable\Actions\CheckLessonConflicts;
use App\Domain\Timetable\Models\Lesson;
use App\Domain\Timetable\Models\TimetableVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Split groups of one class may meet at the same time; a whole-class lesson
 * still clashes with them; teachers and rooms never double-book; lessons of
 * different timetable versions are not compared.
 */
class GroupLessonConflictTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $f;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = TenantDomain::query()->where('domain', 'localhost')->firstOrFail()->tenant;
        $year = $tenant->academicYears()->create(['name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-15', 'is_current' => true]);
        $class = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => '7ა']);
        $groupOne = new StudentGroup(['school_class_id' => $class->id, 'name' => 'ჯგუფი 1']);
        $groupOne->tenant_id = $tenant->id;
        $groupOne->save();
        $groupTwo = new StudentGroup(['school_class_id' => $class->id, 'name' => 'ჯგუფი 2']);
        $groupTwo->tenant_id = $tenant->id;
        $groupTwo->save();

        $this->f = [
            'tenant' => $tenant,
            'year' => $year,
            'class' => $class,
            'groupOne' => $groupOne,
            'groupTwo' => $groupTwo,
            'subject' => $tenant->subjects()->create(['name' => 'ინგლისური']),
            'teacherA' => User::factory()->create(),
            'teacherB' => User::factory()->create(),
        ];
    }

    private function lesson(User $teacher, ?StudentGroup $group, ?TimetableVersion $version = null): Lesson
    {
        $lesson = new Lesson([
            'academic_year_id' => $this->f['year']->id,
            'timetable_version_id' => $version?->id,
            'school_class_id' => $this->f['class']->id,
            'student_group_id' => $group?->id,
            'subject_id' => $this->f['subject']->id,
            'teacher_id' => $teacher->id,
            'day_of_week' => 1,
            'starts_at' => '09:30:00',
            'ends_at' => '10:15:00',
            'status' => Lesson::STATUS_PUBLISHED,
        ]);
        $lesson->tenant_id = $this->f['tenant']->id;
        $lesson->save();

        return $lesson;
    }

    private function conflicts(User $teacher, ?StudentGroup $group, ?TimetableVersion $version = null): int
    {
        return app(CheckLessonConflicts::class)->find(
            tenantId: $this->f['tenant']->id,
            dayOfWeek: 1,
            startsAt: '09:30:00',
            endsAt: '10:15:00',
            teacherId: $teacher->id,
            roomId: null,
            schoolClassId: $this->f['class']->id,
            studentGroupId: $group?->id,
            timetableVersionId: $version?->id,
        )->count();
    }

    public function test_two_groups_of_one_class_can_meet_at_the_same_time(): void
    {
        $this->lesson($this->f['teacherA'], $this->f['groupOne']);

        $this->assertSame(0, $this->conflicts($this->f['teacherB'], $this->f['groupTwo']));
        $this->assertSame(1, $this->conflicts($this->f['teacherB'], $this->f['groupOne']), 'the same group is double-booked');
        $this->assertSame(1, $this->conflicts($this->f['teacherA'], $this->f['groupTwo']), 'the same teacher is double-booked');
    }

    public function test_a_whole_class_lesson_clashes_with_any_group_lesson(): void
    {
        $this->lesson($this->f['teacherA'], $this->f['groupOne']);
        $this->assertSame(1, $this->conflicts($this->f['teacherB'], null));

        // Group 2 clashes with the whole-class lesson, not with group 1's.
        $this->lesson($this->f['teacherB'], null);
        $this->assertSame(1, $this->conflicts(User::factory()->create(), $this->f['groupTwo']));
    }

    public function test_lessons_of_different_timetable_versions_are_not_compared(): void
    {
        $makeVersion = function (string $label, string $from) {
            $version = new TimetableVersion(['academic_year_id' => $this->f['year']->id, 'label' => $label, 'valid_from' => $from, 'status' => TimetableVersion::STATUS_ACTIVE]);
            $version->tenant_id = $this->f['tenant']->id;
            $version->save();

            return $version;
        };
        $v1 = $makeVersion('v209', '2026-09-03');
        $v2 = $makeVersion('v210', '2026-10-21');

        $this->lesson($this->f['teacherA'], null, $v1);

        $this->assertSame(0, $this->conflicts($this->f['teacherA'], null, $v2));
        $this->assertSame(1, $this->conflicts($this->f['teacherA'], null, $v1));
        $this->assertSame(0, $this->conflicts($this->f['teacherA'], null), 'legacy lessons are compared only with each other');
    }
}
