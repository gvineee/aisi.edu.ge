<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Academic structure the EduPage migration needs without losing data
 * (docs/edupage-migration-matrix-2026-10-25.md §3): grade level / short
 * name / home room per class, a second homeroom teacher, split teaching
 * groups, a dated enrollment history and a relational subject on teacher
 * assignments. Purely additive: `school_classes.homeroom_teacher_id`,
 * `students.school_class_id` and `teacher_assignments.subject` keep working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_classes', function (Blueprint $table) {
            $table->unsignedTinyInteger('grade_level')->nullable()->after('name');
            $table->string('short_name', 32)->nullable()->after('grade_level');
            $table->foreignId('home_room_id')->nullable()->after('homeroom_teacher_id')->constrained('rooms')->nullOnDelete();
        });

        Schema::table('subjects', function (Blueprint $table) {
            $table->string('short_name', 64)->nullable()->after('name');
        });

        // Additional staff roles on a class. `homeroom_teacher_id` stays the
        // primary homeroom teacher (report-card review); a second one
        // (EduPage „დამრიგებელი 2“) lives here.
        Schema::create('class_staff_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('school_class_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 32); // co_homeroom
            $table->timestamps();
            $table->unique(['tenant_id', 'school_class_id', 'user_id', 'role'], 'class_staff_roles_unique');
        });

        // A teaching group inside one class (EduPage „დანაყოფები“: ჯგუფი 1,
        // ჯგუფი 2 …). The name is the school's own label; no rule is derived
        // from it.
        Schema::create('student_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('school_class_id')->constrained()->cascadeOnDelete();
            $table->string('name', 64);
            $table->timestamps();
            $table->unique(['tenant_id', 'school_class_id', 'name'], 'student_groups_class_name_unique');
        });

        Schema::create('student_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['student_group_id', 'student_id'], 'student_group_members_unique');
            $table->index(['tenant_id', 'student_id'], 'student_group_members_student_idx');
        });

        // Dated enrollment history. `students.school_class_id` remains the
        // cached current class used by every existing screen.
        Schema::create('student_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->foreignId('school_class_id')->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('status', 32)->default('active'); // active | transferred | left | completed
            $table->timestamps();
            $table->index(['tenant_id', 'student_id', 'academic_year_id'], 'student_enrollments_student_year_idx');
            $table->index(['tenant_id', 'school_class_id', 'status'], 'student_enrollments_class_status_idx');
        });

        Schema::table('teacher_assignments', function (Blueprint $table) {
            $table->foreignId('subject_id')->nullable()->after('subject')->constrained()->nullOnDelete();
            $table->foreignId('student_group_id')->nullable()->after('subject_id')->constrained()->nullOnDelete();
        });

        // Link the free-text subject to Subject only on an exact, same-tenant
        // name match; anything else stays unlinked for a person to resolve.
        DB::table('teacher_assignments')
            ->whereNotNull('subject')
            ->orderBy('id')
            ->chunkById(500, function ($assignments) {
                foreach ($assignments as $assignment) {
                    $subjectId = DB::table('subjects')
                        ->where('tenant_id', $assignment->tenant_id)
                        ->where('name', $assignment->subject)
                        ->value('id');

                    if ($subjectId !== null) {
                        DB::table('teacher_assignments')->where('id', $assignment->id)->update(['subject_id' => $subjectId]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('teacher_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('student_group_id');
            $table->dropConstrainedForeignId('subject_id');
        });
        Schema::dropIfExists('student_enrollments');
        Schema::dropIfExists('student_group_members');
        Schema::dropIfExists('student_groups');
        Schema::dropIfExists('class_staff_roles');
        Schema::table('subjects', function (Blueprint $table) {
            $table->dropColumn('short_name');
        });
        Schema::table('school_classes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('home_room_id');
            $table->dropColumn(['grade_level', 'short_name']);
        });
    }
};
