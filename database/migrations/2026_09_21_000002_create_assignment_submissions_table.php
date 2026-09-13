<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignment_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->string('file_path')->nullable();
            $table->text('text_response')->nullable();
            // Late-submission policy varies by school and must not be
            // invented here (repo brief) — this only ever records the fact
            // that a submission landed after due_at; it never blocks it.
            $table->boolean('is_late')->default(false);
            $table->unsignedInteger('score')->nullable();
            $table->text('feedback')->nullable();
            $table->string('status')->default('not_submitted');
            $table->foreignId('graded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('graded_at')->nullable();
            $table->timestamps();

            $table->unique(['assignment_id', 'student_id']);
            $table->index(['tenant_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_submissions');
    }
};
