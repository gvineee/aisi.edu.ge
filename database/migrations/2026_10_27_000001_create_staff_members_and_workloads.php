<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The school's staff directory, independent of login accounts: a timetable
 * imported from EduPage names 35 teachers, none of whom has an account yet.
 * A staff member links to a user once their invitation is accepted; until
 * then lessons, workloads and homeroom duties hang on the staff member, so
 * the timetable, workload and worked-hours reports are complete from day
 * one. ADR: docs/decisions/0006-staff-directory-workload-and-hours.md.
 *
 * `lessons.teacher_id` and `teacher_assignments.user_id` become nullable;
 * everything that already has a user keeps working unchanged. down() leaves
 * them nullable (rows without an account would otherwise have to go).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('full_name', 160);
            $table->string('short_name', 40)->nullable();
            // A slot the timetable fills with "ახალი" (new, not yet hired).
            $table->boolean('is_placeholder')->default(false);
            $table->string('status', 32)->default('active'); // active | inactive
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id'], 'staff_members_tenant_user_unique');
            $table->index(['tenant_id', 'status'], 'staff_members_tenant_status_idx');
        });

        Schema::table('lessons', function (Blueprint $table) {
            $table->foreignId('teacher_id')->nullable()->change();
            $table->foreignId('staff_member_id')->nullable()->after('teacher_id')->constrained()->restrictOnDelete();
            // Lessons sharing a key are one teaching event attended by
            // several classes (EduPage joint lessons): one teacher, one hour.
            $table->string('joint_key', 64)->nullable()->after('period_number');
            $table->index(['tenant_id', 'staff_member_id', 'day_of_week'], 'lessons_staff_day_idx');
        });

        Schema::table('teacher_assignments', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
            $table->foreignId('staff_member_id')->nullable()->after('user_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('weekly_hours')->nullable()->after('student_group_id');
            // Same meaning as lessons.joint_key: one teacher's hour, several classes.
            $table->string('joint_key', 64)->nullable()->after('weekly_hours');
        });

        // One teacher can teach a subject to two groups of one class (EduPage
        // does, 4 times). The new unique key is created before the old one
        // goes: MySQL keeps it as the index behind the tenant_id foreign key.
        Schema::table('teacher_assignments', function (Blueprint $table) {
            $table->unique(['tenant_id', 'user_id', 'school_class_id', 'subject', 'student_group_id'], 'teacher_assignments_unique_group_assignment');
        });
        // By columns, not name: older local databases carry the default name.
        $old = collect(Schema::getIndexes('teacher_assignments'))
            ->first(fn (array $index) => $index['unique'] && $index['columns'] === ['tenant_id', 'user_id', 'school_class_id', 'subject']);

        if ($old !== null) {
            Schema::table('teacher_assignments', function (Blueprint $table) use ($old) {
                $table->dropUnique($old['name']);
            });
        }

        Schema::table('school_classes', function (Blueprint $table) {
            $table->foreignId('homeroom_staff_member_id')->nullable()->after('homeroom_teacher_id')->constrained('staff_members')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('school_classes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('homeroom_staff_member_id');
        });
        Schema::table('teacher_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('staff_member_id');
            $table->dropColumn(['weekly_hours', 'joint_key']);
            $table->unique(['tenant_id', 'user_id', 'school_class_id', 'subject'], 'teacher_assignments_unique_assignment');
        });
        Schema::table('teacher_assignments', function (Blueprint $table) {
            $table->dropUnique('teacher_assignments_unique_group_assignment');
        });
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropIndex('lessons_staff_day_idx');
            $table->dropConstrainedForeignId('staff_member_id');
            $table->dropColumn('joint_key');
        });
        Schema::dropIfExists('staff_members');
    }
};
