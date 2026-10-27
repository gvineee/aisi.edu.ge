<?php

namespace Tests\Feature\Analytics;

use App\Domain\Academics\Models\TeacherAssignment;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Timetable\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The academic overview counts only recorded data: attendance in the
 * period, published results (never drafts), curriculum progress.
 */
class AcademicOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_classes_subjects_and_pupils_from_recorded_data_only(): void
    {
        $this->travelTo(Carbon::parse('2026-11-30 10:00', 'Asia/Tbilisi'));
        $tenant = TenantDomain::query()->where('domain', 'localhost')->firstOrFail()->tenant;
        $year = $tenant->academicYears()->create(['name' => '2026-2027', 'starts_on' => '2026-09-03', 'ends_on' => '2027-07-01', 'is_current' => true]);
        $a = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => '5ა']);
        $b = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => '5ბ']);
        $math = $tenant->subjects()->create(['name' => 'მათემატიკა']);

        [$manager, $teacher] = array_map(function (string $role) use ($tenant) {
            $user = User::factory()->create();
            TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role, 'is_active' => true]);

            return $user;
        }, [TenantMembership::ROLE_DIRECTOR, TenantMembership::ROLE_TEACHER]);

        $ana = $tenant->students()->create(['school_class_id' => $a->id, 'first_name' => 'ანა', 'last_name' => 'ტესტი', 'is_active' => true]);
        $beka = $tenant->students()->create(['school_class_id' => $a->id, 'first_name' => 'ბექა', 'last_name' => 'ტესტი', 'is_active' => true]);
        $tenant->students()->create(['school_class_id' => $b->id, 'first_name' => 'გიო', 'last_name' => 'ტესტი', 'is_active' => true]);

        $ta = new TeacherAssignment(['user_id' => $teacher->id, 'school_class_id' => $a->id, 'subject' => 'მათემატიკა', 'subject_id' => $math->id]);
        $ta->tenant_id = $tenant->id;
        $ta->save();
        $lesson = new Lesson(['academic_year_id' => $year->id, 'school_class_id' => $a->id, 'subject_id' => $math->id, 'teacher_id' => $teacher->id,
            'day_of_week' => 1, 'starts_at' => '09:30:00', 'ends_at' => '10:15:00', 'status' => Lesson::STATUS_PUBLISHED]);
        $lesson->tenant_id = $tenant->id;
        $lesson->save();

        $now = now();
        $mark = fn ($student, string $date, string $status) => DB::table('attendance_records')->insert([
            'tenant_id' => $tenant->id, 'lesson_id' => $lesson->id, 'student_id' => $student->id, 'occurred_on' => $date,
            'status' => $status, 'marked_by' => $teacher->id, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $mark($ana, '2026-11-02', 'present');
        $mark($ana, '2026-11-09', 'late');
        $mark($beka, '2026-11-02', 'absent');
        $mark($beka, '2026-11-09', 'present');
        $mark($beka, '2026-08-01', 'absent'); // before the year: not counted

        $period = DB::table('assessment_periods')->insertGetId(['tenant_id' => $tenant->id, 'academic_year_id' => $year->id, 'name' => 'I სემესტრი',
            'starts_on' => '2026-09-03', 'ends_on' => '2027-01-31', 'status' => 'open', 'created_at' => $now, 'updated_at' => $now]);
        $assessment = fn (string $status) => DB::table('assessments')->insertGetId(['tenant_id' => $tenant->id, 'teacher_assignment_id' => $ta->id,
            'assessment_period_id' => $period, 'title' => 'ტესტი', 'assessed_on' => '2026-11-10', 'max_score' => '10.00', 'status' => $status,
            'published_at' => $status === 'published' ? $now : null, 'created_by' => $teacher->id, 'created_at' => $now, 'updated_at' => $now]);
        $published = $assessment('published');
        $draft = $assessment('draft');
        $result = fn (int $assessmentId, $student, string $percent) => DB::table('assessment_results')->insert(['tenant_id' => $tenant->id,
            'assessment_id' => $assessmentId, 'student_id' => $student->id, 'raw_score' => '0', 'normalized_percent' => $percent,
            'version' => 1, 'recorded_by' => $teacher->id, 'created_at' => $now, 'updated_at' => $now]);
        $result($published, $ana, '90.00');
        $result($published, $beka, '60.00');
        $result($draft, $ana, '10.00'); // a draft never counts

        $this->actingAs($teacher)->get('/portal/academic-overview')->assertForbidden();

        $this->actingAs($manager)->get('/portal/academic-overview')->assertOk()->assertInertia(fn ($page) => $page
            ->component('portal/academic-overview/index')
            ->where('range', ['from' => '2026-09-03', 'to' => '2026-11-30'])
            ->where('classes.0.className', '5ა')->where('classes.0.students', 2)
            ->where('classes.0.attendance', ['marked' => 4, 'attended' => 3, 'absent' => 1, 'rate' => 75])
            ->where('classes.0.results', ['assessments' => 1, 'results' => 2, 'average' => 75])
            ->where('classes.1.attendance.rate', null)->where('classes.1.results.average', null)
            ->where('selected', null));

        $this->actingAs($manager)->get("/portal/academic-overview?class={$a->id}&period={$period}")->assertInertia(fn ($page) => $page
            ->where('selected.subjects.0.subjectName', 'მათემატიკა')
            ->where('selected.subjects.0.teachers.0', $teacher->name)
            ->where('selected.subjects.0.results.average', 75)
            ->where('selected.students.0.name', 'ბექა ტესტი')->where('selected.students.0.attendance.rate', 50)->where('selected.students.0.average', 60)
            ->where('selected.students.1.average', 90));
    }
}
