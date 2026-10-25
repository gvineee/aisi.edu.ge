<?php

namespace Tests\Feature\Academics;

use App\Domain\Academics\Models\ClassStaffRole;
use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\Student;
use App\Domain\Academics\Models\StudentEnrollment;
use App\Domain\Academics\Models\StudentGroup;
use App\Domain\Learning\Models\AbsenceNote;
use App\Domain\Learning\Models\AttendanceRecord;
use App\Domain\Learning\Models\LessonRecord;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Timetable\Models\BellPeriod;
use App\Domain\Timetable\Models\Lesson;
use App\Domain\Timetable\Models\TimetableVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Class profile (co-homeroom, groups), enrollment history, group-aware
 * rosters, the class register topic, absence notes, bell schedule and
 * timetable versions — all with synthetic data.
 */
class ClassStructureAndRegisterTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private SchoolClass $class;

    private User $manager;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        // A Monday, so weekday-bound lessons occur "today".
        $this->travelTo(Carbon::parse('2026-10-26 10:00', 'Asia/Tbilisi'));

        $this->tenant = TenantDomain::query()->where('domain', 'localhost')->firstOrFail()->tenant;
        $year = $this->tenant->academicYears()->create(['name' => '2026-2027', 'starts_on' => '2026-09-03', 'ends_on' => '2027-07-01', 'is_current' => true]);
        $this->class = $this->tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => '7ა']);
        $this->manager = $this->member(TenantMembership::ROLE_ACADEMIC_MANAGER);
        $this->teacher = $this->member(TenantMembership::ROLE_TEACHER);
    }

    private function member(string $role, ?Tenant $tenant = null): User
    {
        $user = User::factory()->create();
        TenantMembership::create(['tenant_id' => ($tenant ?? $this->tenant)->id, 'user_id' => $user->id, 'role' => $role, 'is_active' => true]);

        return $user;
    }

    private function pupil(string $first, ?SchoolClass $class = null): Student
    {
        $student = new Student(['school_class_id' => ($class ?? $this->class)->id, 'first_name' => $first, 'last_name' => 'ტესტი', 'is_active' => true]);
        $student->tenant_id = $this->tenant->id;
        $student->save();

        return $student;
    }

    private function group(string $name, array $students): StudentGroup
    {
        $this->actingAs($this->manager)->post("/portal/academic-structure/school-classes/{$this->class->id}/groups", [
            'name' => $name,
            'student_ids' => array_map(fn (Student $s) => $s->id, $students),
        ])->assertSessionHasNoErrors();

        return StudentGroup::query()->where('name', $name)->sole();
    }

    private function lesson(?StudentGroup $group = null, ?User $teacher = null): Lesson
    {
        $subject = $this->tenant->subjects()->firstOrCreate(['name' => 'ინგლისური']);
        $lesson = new Lesson([
            'academic_year_id' => $this->class->academic_year_id,
            'school_class_id' => $this->class->id,
            'student_group_id' => $group?->id,
            'subject_id' => $subject->id,
            'teacher_id' => ($teacher ?? $this->teacher)->id,
            'day_of_week' => 1,
            'starts_at' => '09:30:00',
            'ends_at' => '10:15:00',
            'status' => Lesson::STATUS_PUBLISHED,
        ]);
        $lesson->tenant_id = $this->tenant->id;
        $lesson->save();

        return $lesson;
    }

    public function test_the_class_profile_sets_details_and_a_second_homeroom_teacher(): void
    {
        $room = $this->tenant->rooms()->create(['name' => '201']);

        $this->actingAs($this->manager)->get("/portal/academic-structure/school-classes/{$this->class->id}")->assertOk();
        $this->actingAs($this->manager)->put("/portal/academic-structure/school-classes/{$this->class->id}", [
            'grade_level' => 7, 'short_name' => '7A', 'home_room_id' => $room->id, 'co_homeroom_ids' => [$this->teacher->id],
        ])->assertSessionHasNoErrors();

        $this->class->refresh();
        $this->assertSame([7, '7A', $room->id], [$this->class->grade_level, $this->class->short_name, $this->class->home_room_id]);
        $this->assertTrue(ClassStaffRole::query()->where('school_class_id', $this->class->id)->where('user_id', $this->teacher->id)->exists());
        $this->assertDatabaseHas('audit_events', ['action' => 'school_class.profile_updated']);

        // Someone who is not a teacher of this school cannot be made co-homeroom.
        $outsider = User::factory()->create();
        $this->actingAs($this->manager)->put("/portal/academic-structure/school-classes/{$this->class->id}", ['co_homeroom_ids' => [$outsider->id]])
            ->assertSessionHasErrors('co_homeroom_ids');

        // A teacher cannot edit the structure, another school cannot see the class.
        $this->actingAs($this->teacher)->get("/portal/academic-structure/school-classes/{$this->class->id}")->assertForbidden();
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'locale' => 'ka', 'timezone' => 'Asia/Tbilisi', 'is_active' => true]);
        $otherYear = $other->academicYears()->create(['name' => '2026-2027', 'starts_on' => '2026-09-03', 'ends_on' => '2027-07-01', 'is_current' => true]);
        $foreign = $other->schoolClasses()->create(['academic_year_id' => $otherYear->id, 'name' => '1ა']);
        $this->actingAs($this->manager)->get("/portal/academic-structure/school-classes/{$foreign->id}")->assertNotFound();
    }

    public function test_groups_hold_only_pupils_of_their_class_and_a_used_group_cannot_be_deleted(): void
    {
        $ana = $this->pupil('ანა');
        $otherClass = $this->tenant->schoolClasses()->create(['academic_year_id' => $this->class->academic_year_id, 'name' => '8ა']);
        $stranger = $this->pupil('გიო', $otherClass);

        $this->actingAs($this->manager)->post("/portal/academic-structure/school-classes/{$this->class->id}/groups", [
            'name' => 'ჯგუფი 1', 'student_ids' => [$ana->id, $stranger->id],
        ])->assertSessionHasErrors('student_ids');

        $group = $this->group('ჯგუფი 1', [$ana]);
        $this->assertSame([$ana->id], $group->students()->pluck('students.id')->all());

        $this->lesson($group);
        $this->actingAs($this->manager)->delete("/portal/academic-structure/school-classes/{$this->class->id}/groups/{$group->id}")
            ->assertSessionHasErrors('group');
        $this->assertNotNull($group->fresh());
    }

    public function test_enrollment_history_follows_joining_transfer_and_leaving(): void
    {
        $target = $this->tenant->schoolClasses()->create(['academic_year_id' => $this->class->academic_year_id, 'name' => '7ბ']);

        $this->actingAs($this->manager)->post('/portal/academic-structure/students', [
            'school_class_id' => $this->class->id, 'first_name' => 'ნინო', 'last_name' => 'ტესტი',
        ])->assertSessionHasNoErrors();
        $student = Student::query()->where('first_name', 'ნინო')->sole();

        $update = fn (SchoolClass $class, bool $active) => $this->actingAs($this->manager)->put("/portal/academic-structure/students/{$student->id}", [
            'school_class_id' => $class->id, 'first_name' => 'ნინო', 'last_name' => 'ტესტი', 'is_active' => $active ? '1' : '0',
        ])->assertSessionHasNoErrors();

        $update($target, true);
        $update($target, false);

        $spans = StudentEnrollment::query()->where('student_id', $student->id)->orderBy('id')->get();
        $this->assertSame([[$this->class->id, 'transferred'], [$target->id, 'left']], $spans->map(fn ($e) => [$e->school_class_id, $e->status])->all());
        $this->assertSame('2026-10-26', $spans[1]->ends_on->toDateString());
    }

    public function test_a_group_lesson_marks_attendance_only_for_its_members(): void
    {
        $ana = $this->pupil('ანა');
        $beka = $this->pupil('ბექა');
        $lesson = $this->lesson($this->group('ჯგუფი 1', [$ana]));

        $this->actingAs($this->teacher)->get("/lessons/{$lesson->id}/attendance?date=2026-10-26")
            ->assertInertia(fn ($page) => $page->has('students', 1)->where('students.0.id', $ana->id));

        $this->actingAs($this->teacher)->post("/lessons/{$lesson->id}/attendance", [
            'occurred_on' => '2026-10-26',
            'records' => [['student_id' => $beka->id, 'status' => 'present']],
        ])->assertSessionHasErrors('records.0.student_id');
    }

    public function test_the_class_register_topic_is_written_by_the_lessons_teacher_for_a_real_occurrence(): void
    {
        $lesson = $this->lesson();
        $otherTeacher = $this->member(TenantMembership::ROLE_TEACHER);

        $this->actingAs($this->teacher)->post("/portal/teacher/lessons/{$lesson->id}/record", ['occurs_on' => '2026-10-26', 'topic' => 'Present Simple'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Present Simple', LessonRecord::query()->where('lesson_id', $lesson->id)->sole()->topic);
        $this->assertDatabaseHas('audit_events', ['action' => 'lesson_record.created']);

        $this->actingAs($this->teacher)->post("/portal/teacher/lessons/{$lesson->id}/record", ['occurs_on' => '2026-10-27', 'topic' => 'x'])
            ->assertSessionHasErrors('occurs_on');
        $this->actingAs($this->teacher)->post("/portal/teacher/lessons/{$lesson->id}/record", ['occurs_on' => '2026-11-02', 'topic' => 'x'])
            ->assertSessionHasErrors('occurs_on');
        $this->actingAs($otherTeacher)->post("/portal/teacher/lessons/{$lesson->id}/record", ['occurs_on' => '2026-10-26', 'topic' => 'x'])
            ->assertForbidden();

        $this->actingAs($this->teacher)->get("/portal/teacher/lessons/{$lesson->id}")
            ->assertInertia(fn ($page) => $page->where('todayTopic', 'Present Simple')->where('lesson.heldToday', true));
    }

    public function test_an_approved_absence_note_excuses_absences_and_only_reviewers_decide(): void
    {
        $ana = $this->pupil('ანა');
        $guardian = $this->member(TenantMembership::ROLE_GUARDIAN);
        $link = new GuardianLink(['user_id' => $guardian->id, 'student_id' => $ana->id, 'can_view_academic' => true, 'is_active' => true]);
        $link->tenant_id = $this->tenant->id;
        $link->save();
        $lesson = $this->lesson();
        $absent = new AttendanceRecord(['lesson_id' => $lesson->id, 'student_id' => $ana->id, 'occurred_on' => '2026-10-26', 'status' => 'absent', 'marked_by' => $this->teacher->id]);
        $absent->tenant_id = $this->tenant->id;
        $absent->save();

        $this->actingAs($guardian)->post('/portal/absence-notes', [
            'student_id' => $ana->id, 'starts_on' => '2026-10-26', 'ends_on' => '2026-10-27', 'reason' => 'სიცხე',
        ])->assertSessionHasNoErrors();
        $note = AbsenceNote::query()->sole();
        $this->assertDatabaseMissing('audit_events', ['action' => 'absence_note.submitted', 'meta' => json_encode(['reason' => 'სიცხე'])]);

        // An unrelated teacher may not review; the homeroom teacher may.
        $this->actingAs($this->teacher)->post("/portal/absence-notes/{$note->id}/review", ['decision' => 'approve'])->assertForbidden();
        $this->class->update(['homeroom_teacher_id' => $this->teacher->id]);
        $this->actingAs($this->teacher)->post("/portal/absence-notes/{$note->id}/review", ['decision' => 'approve'])->assertSessionHasNoErrors();

        $this->assertSame('excused', $absent->fresh()->status);
        $this->assertSame(AbsenceNote::STATUS_APPROVED, $note->fresh()->status);

        // An absence marked later on a covered day is excused as well.
        $this->travelTo(Carbon::parse('2026-11-02 10:00', 'Asia/Tbilisi'));
        $this->actingAs($this->teacher)->post("/lessons/{$lesson->id}/attendance", [
            'occurred_on' => '2026-10-26',
            'records' => [['student_id' => $ana->id, 'status' => 'absent']],
        ]);
        $this->assertSame('excused', $absent->fresh()->status);

        // A revoked guardian can no longer write.
        $link->update(['is_active' => false]);
        $this->actingAs($guardian)->post('/portal/absence-notes', [
            'student_id' => $ana->id, 'starts_on' => '2026-11-02', 'ends_on' => '2026-11-02', 'reason' => 'x',
        ])->assertForbidden();
    }

    public function test_bell_schedule_rejects_overlaps_and_activating_a_version_archives_the_previous(): void
    {
        $yearId = $this->class->academic_year_id;

        $this->actingAs($this->manager)->put("/portal/timetable/years/{$yearId}/bell-periods", ['periods' => [
            ['starts_at' => '09:30', 'ends_at' => '10:15'], ['starts_at' => '10:10', 'ends_at' => '10:55'],
        ]])->assertSessionHasErrors('periods.1.starts_at');

        $this->actingAs($this->manager)->put("/portal/timetable/years/{$yearId}/bell-periods", ['periods' => [
            ['starts_at' => '09:30', 'ends_at' => '10:15'], ['starts_at' => '10:20', 'ends_at' => '11:05'],
        ]])->assertSessionHasNoErrors();
        $this->assertSame(2, BellPeriod::query()->where('academic_year_id', $yearId)->count());

        foreach (['v209' => '2026-09-03', 'v210' => '2026-10-21'] as $label => $from) {
            $this->actingAs($this->manager)->post("/portal/timetable/years/{$yearId}/versions", ['label' => $label, 'valid_from' => $from])->assertSessionHasNoErrors();
        }
        [$v209, $v210] = TimetableVersion::query()->orderBy('id')->get()->all();

        $this->actingAs($this->manager)->post("/portal/timetable/versions/{$v209->id}/activate");
        $this->actingAs($this->manager)->post("/portal/timetable/versions/{$v210->id}/activate");
        $this->assertSame([TimetableVersion::STATUS_ARCHIVED, TimetableVersion::STATUS_ACTIVE], [$v209->fresh()->status, $v210->fresh()->status]);

        $this->actingAs($this->teacher)->post("/portal/timetable/versions/{$v209->id}/activate")->assertForbidden();
    }

    public function test_a_lesson_cannot_use_a_group_of_another_class(): void
    {
        $otherClass = $this->tenant->schoolClasses()->create(['academic_year_id' => $this->class->academic_year_id, 'name' => '8ა']);
        $foreignGroup = new StudentGroup(['school_class_id' => $otherClass->id, 'name' => 'ჯგუფი 1']);
        $foreignGroup->tenant_id = $this->tenant->id;
        $foreignGroup->save();
        $admin = $this->member(TenantMembership::ROLE_ADMIN);
        $admin->forceFill(['two_factor_secret' => encrypt('x'), 'two_factor_confirmed_at' => now()])->save();
        $subject = $this->tenant->subjects()->create(['name' => 'მათემატიკა']);

        $this->actingAs($this->manager)->post('/lessons', [
            'academic_year_id' => $this->class->academic_year_id, 'school_class_id' => $this->class->id, 'student_group_id' => $foreignGroup->id,
            'subject_id' => $subject->id, 'teacher_id' => $this->teacher->id, 'day_of_week' => 2,
            'starts_at' => '09:30', 'ends_at' => '10:15', 'status' => 'published',
        ])->assertSessionHasErrors('student_group_id');
    }
}
