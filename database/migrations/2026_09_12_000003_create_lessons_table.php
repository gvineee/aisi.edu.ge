<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->foreignId('school_class_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            // 1 = Monday ... 7 = Sunday (ISO-8601), matching Carbon::dayOfWeekIso.
            $table->unsignedTinyInteger('day_of_week');
            // Wall-clock time in the school's own timezone (Asia/Tbilisi) —
            // a recurring weekly slot, not a UTC instant, same reasoning as
            // date-only academic fields (docs/02 §5.3).
            $table->time('starts_at');
            $table->time('ends_at');
            $table->string('online_url')->nullable();
            $table->string('status')->default('draft'); // draft | published
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'school_class_id', 'day_of_week']);
            $table->index(['tenant_id', 'teacher_id', 'day_of_week']);
            $table->index(['tenant_id', 'room_id', 'day_of_week']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lessons');
    }
};
