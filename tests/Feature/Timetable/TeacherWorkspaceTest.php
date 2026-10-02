<?php

namespace Tests\Feature\Timetable;

use App\Domain\Learning\Models\AttendanceRecord;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Timetable\Models\Lesson;
use App\Domain\Timetable\Models\SubstitutionAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * CLAUDE-PLATFORM-MODULES §9: the teacher home shows own and substitute
 * lessons; a substitute's roster/attendance access is bound to the covered
 * lesson and date; attendance can only be written for the lesson's own
 * class roster.
 */
class TeacherWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_substitute_scope_and_roster_bound_attendance(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 08:00', 'Asia/Tbilisi')); // Monday
        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;
        $year = $tenant->academicYears()->create(['name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-15', 'is_current' => true]);
        $class = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => 'VI']);
        $otherClass = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => 'VII']);
        $subject = $tenant->subjects()->create(['name' => 'მათემატიკა']);

        $owner = User::factory()->create();
        $sub = User::factory()->create();
        $stranger = User::factory()->create();
        foreach ([$owner, $sub, $stranger] as $u) {
            TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $u->id, 'role' => TenantMembership::ROLE_TEACHER, 'is_active' => true]);
        }

        $lesson = new Lesson(['academic_year_id' => $year->id, 'school_class_id' => $class->id, 'subject_id' => $subject->id, 'teacher_id' => $owner->id, 'day_of_week' => 1, 'starts_at' => '09:00:00', 'ends_at' => '09:45:00', 'status' => 'published']);
        $lesson->tenant_id = $tenant->id;
        $lesson->save();

        $student = $tenant->students()->create(['school_class_id' => $class->id, 'first_name' => 'ნიკა', 'last_name' => 'დ.', 'is_active' => true]);
        $outsider = $tenant->students()->create(['school_class_id' => $otherClass->id, 'first_name' => 'ანა', 'last_name' => 'ბ.', 'is_active' => true]);

        $this->actingAs($owner)->get('/portal/teacher')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('portal/teacher/workspace')->where('lessons.0.attendanceMarked', 0)->where('lessons.0.roster', 1));

        // Attendance cannot be written for a student outside this class.
        $this->actingAs($owner)->post("/lessons/{$lesson->id}/attendance", ['occurred_on' => '2026-10-05', 'records' => [['student_id' => $outsider->id, 'status' => 'present']]])
            ->assertSessionHasErrors('records.0.student_id');
        $this->assertSame(0, AttendanceRecord::query()->count());

        // Not assigned → no access.
        $this->actingAs($sub)->get("/portal/teacher/lessons/{$lesson->id}")->assertForbidden();
        $this->actingAs($sub)->post("/lessons/{$lesson->id}/attendance", ['occurred_on' => '2026-10-05', 'records' => [['student_id' => $student->id, 'status' => 'present']]])->assertForbidden();

        $assignment = new SubstitutionAssignment(['lesson_id' => $lesson->id, 'absent_teacher_id' => $owner->id, 'substitute_teacher_id' => $sub->id, 'date' => '2026-10-05', 'status' => 'assigned', 'created_by' => $owner->id]);
        $assignment->tenant_id = $tenant->id;
        $assignment->save();

        // Substitute: today only, roster only.
        $this->actingAs($sub)->get('/portal/teacher')->assertInertia(fn (Assert $page) => $page->where('lessons.0.isSubstitute', true));
        $this->actingAs($sub)->get("/portal/teacher/lessons/{$lesson->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('isSubstitute', true)->where('assignments', [])->has('roster', 1));
        $this->actingAs($sub)->post("/lessons/{$lesson->id}/attendance", ['occurred_on' => '2026-10-05', 'records' => [['student_id' => $student->id, 'status' => 'present']]])->assertSessionHasNoErrors();
        $this->actingAs($sub)->post("/lessons/{$lesson->id}/attendance", ['occurred_on' => '2026-10-12', 'records' => [['student_id' => $student->id, 'status' => 'present']]])->assertForbidden();

        $this->actingAs($stranger)->get("/portal/teacher/lessons/{$lesson->id}")->assertForbidden();

        $this->travelTo(Carbon::parse('2026-10-06 08:00', 'Asia/Tbilisi'));
        $this->actingAs($sub)->get("/portal/teacher/lessons/{$lesson->id}")->assertForbidden();
    }
}
