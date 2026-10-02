<?php

namespace Tests\Feature\Analytics;

use App\Domain\Learning\Models\AttendanceRecord;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Timetable\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * CLAUDE-PLATFORM-MODULES §13: metrics carry definition/period/denominator,
 * exclude inactive students, never invent values, stay tenant-scoped and
 * are director/admin only.
 */
class SchoolPulseTest extends TestCase
{
    use RefreshDatabase;

    public function test_pulse_metrics_are_defined_scoped_and_honest(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 12:00', 'Asia/Tbilisi'));
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;

        $director = User::factory()->create();
        $teacher = User::factory()->create();
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $director->id, 'role' => TenantMembership::ROLE_DIRECTOR, 'is_active' => true]);
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $teacher->id, 'role' => TenantMembership::ROLE_TEACHER, 'is_active' => true]);

        // With no attendance yet, rates say "no data" rather than 0%.
        $this->actingAs($director)->get('/portal/director')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('portal/director/pulse')
                ->where('metrics.0.key', 'attendance_today')
                ->where('metrics.0.status', 'no_data')
                ->where('metrics.0.value', null)
                ->where('metrics.5.key', 'collection_current')
                ->where('metrics.5.status', 'no_data')
                ->where('attendanceTrend', []));

        $year = $tenant->academicYears()->create(['name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-15', 'is_current' => true]);
        $class = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => 'A']);
        $subject = $tenant->subjects()->create(['name' => 'Math']);
        $lesson = new Lesson(['academic_year_id' => $year->id, 'school_class_id' => $class->id, 'subject_id' => $subject->id, 'teacher_id' => $teacher->id, 'day_of_week' => 1, 'starts_at' => '09:00:00', 'ends_at' => '09:45:00', 'status' => 'published']);
        $lesson->tenant_id = $tenant->id;
        $lesson->save();

        $statuses = ['present', 'present', 'late', 'absent'];
        foreach ($statuses as $i => $status) {
            $student = $tenant->students()->create(['school_class_id' => $class->id, 'first_name' => "S{$i}", 'last_name' => 'T', 'is_active' => true]);
            $this->record($tenant, $lesson, $student->id, $teacher->id, '2026-10-05', $status);
        }
        // An inactive student's record is excluded by rule.
        $inactive = $tenant->students()->create(['school_class_id' => $class->id, 'first_name' => 'Old', 'last_name' => 'T', 'is_active' => false]);
        $this->record($tenant, $lesson, $inactive->id, $teacher->id, '2026-10-05', 'absent');

        $this->actingAs($director)->get('/portal/director')
            ->assertInertia(fn (Assert $page) => $page
                ->where('metrics.0.value', 75)
                ->where('metrics.0.numerator', 3)
                ->where('metrics.0.denominator', 4)
                ->where('metrics.1.value', 25)
                ->where('metrics.2.value', 1)
                ->where('attendanceTrend.0.date', '2026-10-05')
                ->where('attendanceTrend.0.records', 4));

        $this->actingAs($teacher)->get('/portal/director')->assertForbidden();

        // Another tenant's director sees none of this.
        $other = Tenant::create(['slug' => 'other-school', 'name' => 'Other', 'locale' => 'ka', 'timezone' => 'Asia/Tbilisi', 'is_active' => true]);
        TenantDomain::create(['tenant_id' => $other->id, 'domain' => 'other-school.test', 'is_primary' => true]);
        $outsider = User::factory()->create();
        TenantMembership::create(['tenant_id' => $other->id, 'user_id' => $outsider->id, 'role' => TenantMembership::ROLE_DIRECTOR, 'is_active' => true]);

        $this->actingAs($outsider)->get('http://other-school.test/portal/director', ['Host' => 'other-school.test'])
            ->assertInertia(fn (Assert $page) => $page->where('metrics.0.status', 'no_data')->where('metrics.2.value', 0));
    }

    private function record(Tenant $tenant, Lesson $lesson, int $studentId, int $teacherId, string $date, string $status): void
    {
        $record = new AttendanceRecord(['lesson_id' => $lesson->id, 'student_id' => $studentId, 'occurred_on' => $date, 'status' => $status, 'marked_by' => $teacherId]);
        $record->tenant_id = $tenant->id;
        $record->save();
    }
}
