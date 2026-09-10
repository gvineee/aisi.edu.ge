<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->date('occurred_on');
            // present | late | absent | excused
            $table->string('status');
            $table->string('comment')->nullable();
            $table->foreignId('marked_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One record per student per actual lesson occurrence — the
            // invariant docs/02 calls out explicitly.
            $table->unique(['tenant_id', 'lesson_id', 'student_id', 'occurred_on'], 'attendance_unique_occurrence');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};
