<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('substitution_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            // Nullable: an admin may assign coverage for a single lesson
            // without first (or ever) filing a staff_absences row.
            $table->foreignId('absence_id')->nullable()->constrained('staff_absences')->nullOnDelete();
            $table->foreignId('absent_teacher_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('substitute_teacher_id')->constrained('users')->cascadeOnDelete();
            // Calendar date this specific assignment covers — lessons are a
            // recurring weekly template (day_of_week + time), so the same
            // lesson_id needs one row per covered date.
            $table->date('date');
            $table->string('status')->default('assigned'); // assigned | cancelled
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'substitute_teacher_id', 'date']);
            $table->index(['tenant_id', 'lesson_id', 'date']);
            $table->index(['tenant_id', 'absence_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('substitution_assignments');
    }
};
