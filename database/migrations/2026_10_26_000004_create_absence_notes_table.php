<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A guardian's absence note for a pupil (EduPage „გაცდენის შენიშვნები“):
 * submitted → approved / rejected by the homeroom teacher or school
 * administration. Approval turns the covered absences into excused ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('absence_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('submitted_by')->constrained('users')->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->text('reason');
            $table->string('status', 16)->default('pending'); // pending | approved | rejected
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status'], 'absence_notes_status_idx');
            $table->index(['tenant_id', 'student_id', 'starts_on'], 'absence_notes_student_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('absence_notes');
    }
};
