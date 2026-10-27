<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Curriculum plans: the ordered topics a class works through in a subject
 * over the year. The class register (lesson_records) names the plan topic
 * a lesson covered, which is where progress comes from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('curriculum_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->foreignId('school_class_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16); // draft | approved
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'school_class_id', 'subject_id'], 'curriculum_plans_class_subject_unique');
        });

        Schema::create('curriculum_topics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('curriculum_plan_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('title', 200);
            $table->unsignedSmallInteger('planned_hours')->default(1);
            $table->date('planned_from')->nullable();
            $table->date('planned_to')->nullable();
            $table->timestamps();
            $table->index(['curriculum_plan_id', 'position'], 'curriculum_topics_plan_position_idx');
        });

        Schema::table('lesson_records', function (Blueprint $table) {
            $table->foreignId('curriculum_topic_id')->nullable()->after('topic')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lesson_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('curriculum_topic_id');
        });
        Schema::dropIfExists('curriculum_topics');
        Schema::dropIfExists('curriculum_plans');
    }
};
