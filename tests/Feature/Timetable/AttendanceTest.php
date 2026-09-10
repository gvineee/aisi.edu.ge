<?php

namespace Tests\Feature\Timetable;

use App\Domain\Learning\Models\AttendanceRecord;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Timetable\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers docs/02 critical check #3 for the actual attendance write path,
 * plus CLAUDE.md invariant #7 (attendance changes are audited).
 */
class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    private function makeLessonWithStudents(): array
    {
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;
        $year = $tenant->academicYears()->create([
            'name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-15', 'is_current' => true,
        ]);
        $class = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => 'A']);
        $subject = $tenant->subjects()->create(['name' => 'Math']);
        $teacher = User::factory()->create();
        $otherTeacher = User::factory()->create();

        $lesson = new Lesson([
            'academic_year_id' => $year->id, 'school_class_id' => $class->id,
            'subject_id' => $subject->id, 'teacher_id' => $teacher->id,
            'day_of_week' => 1, 'starts_at' => '09:00:00', 'ends_at' => '09:45:00',
            'status' => 'published',
        ]);
        $lesson->tenant_id = $tenant->id;
        $lesson->save();

        $student = $tenant->students()->create([
            'school_class_id' => $class->id, 'first_name' => 'Nika', 'last_name' => 'Test', 'is_active' => true,
        ]);

        return compact('tenant', 'lesson', 'student', 'teacher', 'otherTeacher');
    }

    public function test_the_assigned_teacher_can_record_attendance(): void
    {
        $f = $this->makeLessonWithStudents();

        $response = $this->actingAs($f['teacher'])->post(
            route('attendance.store', $f['lesson']),
            [
                'occurred_on' => '2026-09-14',
                'records' => [
                    ['student_id' => $f['student']->id, 'status' => 'present'],
                ],
            ],
        );

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('attendance_records', [
            'lesson_id' => $f['lesson']->id,
            'student_id' => $f['student']->id,
            'status' => 'present',
        ]);
    }

    public function test_a_different_teacher_cannot_record_attendance_for_this_lesson(): void
    {
        $f = $this->makeLessonWithStudents();

        $this->actingAs($f['otherTeacher'])->post(
            route('attendance.store', $f['lesson']),
            [
                'occurred_on' => '2026-09-14',
                'records' => [['student_id' => $f['student']->id, 'status' => 'present']],
            ],
        )->assertForbidden();

        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_resubmitting_updates_the_same_record_instead_of_duplicating(): void
    {
        $f = $this->makeLessonWithStudents();

        $payload = [
            'occurred_on' => '2026-09-14',
            'records' => [['student_id' => $f['student']->id, 'status' => 'present']],
        ];

        $this->actingAs($f['teacher'])->post(route('attendance.store', $f['lesson']), $payload);
        $this->actingAs($f['teacher'])->post(route('attendance.store', $f['lesson']), [
            'occurred_on' => '2026-09-14',
            'records' => [['student_id' => $f['student']->id, 'status' => 'late']],
        ]);

        $this->assertDatabaseCount('attendance_records', 1);
        $this->assertDatabaseHas('attendance_records', ['status' => 'late']);
    }

    public function test_recording_attendance_writes_an_audit_event(): void
    {
        $f = $this->makeLessonWithStudents();

        $this->actingAs($f['teacher'])->post(route('attendance.store', $f['lesson']), [
            'occurred_on' => '2026-09-14',
            'records' => [['student_id' => $f['student']->id, 'status' => 'absent']],
        ]);

        $record = AttendanceRecord::query()->first();

        $this->assertDatabaseHas('audit_events', [
            'tenant_id' => $f['tenant']->id,
            'actor_id' => $f['teacher']->id,
            'action' => 'attendance.recorded',
            'subject_type' => AttendanceRecord::class,
            'subject_id' => $record->id,
        ]);
    }
}
