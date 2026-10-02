<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('school_class_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assessment_period_id')->constrained()->cascadeOnDelete();
            // draft | teacher_complete | homeroom_review | director_approved | published
            $table->string('status')->default('draft');
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            // Explicit short names: MySQL's 64-char identifier limit (see
            // docs/decisions/0001-stack-and-versions.md).
            $table->unique(['student_id', 'assessment_period_id'], 'report_cards_student_period_uq');
            $table->index(['tenant_id', 'school_class_id', 'assessment_period_id'], 'report_cards_class_period_idx');
            $table->index(['tenant_id', 'status'], 'report_cards_tenant_status_idx');
        });

        Schema::create('report_card_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_card_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_assignment_id')->constrained()->cascadeOnDelete();
            $table->string('subject_label');
            // Informational summary of the period's published assessments;
            // the official mark is the teacher's final_mark below.
            $table->unsignedInteger('assessments_count')->default(0);
            $table->decimal('average_percent', 5, 2)->nullable();
            $table->string('final_mark', 32)->nullable();
            $table->text('teacher_comment')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['report_card_id', 'teacher_assignment_id'], 'report_card_subjects_card_ta_uq');
            $table->index(['tenant_id', 'teacher_assignment_id'], 'report_card_subjects_tenant_ta_idx');
        });

        Schema::create('report_card_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_card_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->string('kind'); // homeroom | director
            $table->text('body');
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'report_card_id'], 'report_card_comments_card_idx');
        });

        Schema::create('report_card_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_card_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->string('from_status');
            $table->string('to_status');
            $table->string('note', 1000)->nullable();
            $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'report_card_id'], 'report_card_approvals_card_idx');
        });

        Schema::create('report_card_publications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_card_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->string('verification_code', 16)->unique();
            // Frozen copy of exactly what was approved and published — the
            // document families see, independent of later revisions.
            $table->json('snapshot');
            $table->foreignId('published_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('published_at');

            $table->unique(['report_card_id', 'revision'], 'report_card_publications_revision_uq');
            $table->index(['tenant_id', 'report_card_id'], 'report_card_publications_card_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_card_publications');
        Schema::dropIfExists('report_card_approvals');
        Schema::dropIfExists('report_card_comments');
        Schema::dropIfExists('report_card_subjects');
        Schema::dropIfExists('report_cards');
    }
};
