<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Competencies (CLAUDE-PLATFORM-MODULES §6): the school's own framework
 * (none seeded), level descriptors, and teachers' dated observations of a
 * student's level — shared with the family only when the teacher marks it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('competency_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('competency_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('level');
            $table->string('label', 60);
            $table->text('descriptor')->nullable();
            $table->timestamps();

            $table->unique(['competency_id', 'level']);
        });

        Schema::create('student_competency_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('competency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('competency_level_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_assignment_id')->constrained()->cascadeOnDelete();
            $table->date('observed_on');
            $table->text('note')->nullable();
            $table->boolean('shared_with_family')->default(false);
            $table->foreignId('recorded_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'student_id', 'competency_id'], 'competency_obs_student_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_competency_observations');
        Schema::dropIfExists('competency_levels');
        Schema::dropIfExists('competencies');
    }
};
