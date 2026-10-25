<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Timetable versions with a validity window, the bell schedule, group
 * lessons and the per-occurrence class-journal entry (lesson topic). Legacy
 * lessons keep `timetable_version_id = null` and behave exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timetable_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->string('label', 120);
            $table->string('source_version', 64)->nullable(); // e.g. EduPage „v210“
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->string('status', 32)->default('draft'); // draft | active | archived
            $table->timestamps();
            $table->index(['tenant_id', 'academic_year_id', 'status'], 'timetable_versions_year_status_idx');
        });

        Schema::create('bell_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('number');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'academic_year_id', 'number'], 'bell_periods_year_number_unique');
        });

        Schema::table('lessons', function (Blueprint $table) {
            $table->foreignId('timetable_version_id')->nullable()->after('academic_year_id')->constrained()->nullOnDelete();
            $table->foreignId('student_group_id')->nullable()->after('school_class_id')->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('period_number')->nullable()->after('day_of_week');
        });

        // One journal entry per lesson occurrence: what was taught that day.
        Schema::create('lesson_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->date('occurs_on');
            $table->text('topic')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'lesson_id', 'occurs_on'], 'lesson_records_occurrence_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_records');
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropConstrainedForeignId('timetable_version_id');
            $table->dropConstrainedForeignId('student_group_id');
            $table->dropColumn('period_number');
        });
        Schema::dropIfExists('bell_periods');
        Schema::dropIfExists('timetable_versions');
    }
};
