<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assessment_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assessment_category_id')->nullable()->constrained()->nullOnDelete();
            // Snapshot of the scale active when the assessment was created;
            // null when the school has not configured one yet (final grade
            // then stays empty — no invented grading policy).
            $table->foreignId('grade_scale_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->date('assessed_on');
            $table->decimal('max_score', 6, 2);
            $table->string('status')->default('draft'); // draft | published
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            // Explicit short names: MySQL's 64-char identifier limit (see
            // docs/decisions/0001-stack-and-versions.md).
            $table->index(['tenant_id', 'teacher_assignment_id', 'assessment_period_id'], 'assessments_ta_period_idx');
            $table->index(['tenant_id', 'status'], 'assessments_tenant_status_idx');
        });

        Schema::create('assessment_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            // Raw score, maximum (on the assessment), normalized percent and
            // final grade are stored separately (CLAUDE-PLATFORM-MODULES §6).
            $table->decimal('raw_score', 6, 2);
            $table->decimal('normalized_percent', 5, 2);
            $table->string('final_grade', 32)->nullable();
            $table->text('comment')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('recorded_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['assessment_id', 'student_id'], 'assessment_results_assessment_student_uq');
            $table->index(['tenant_id', 'student_id'], 'assessment_results_tenant_student_idx');
        });

        Schema::create('assessment_result_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assessment_result_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->decimal('raw_score', 6, 2);
            $table->decimal('normalized_percent', 5, 2);
            $table->string('final_grade', 32)->nullable();
            $table->string('reason', 500)->nullable();
            $table->foreignId('changed_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['assessment_result_id', 'version'], 'assessment_result_revisions_version_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_result_revisions');
        Schema::dropIfExists('assessment_results');
        Schema::dropIfExists('assessments');
    }
};
