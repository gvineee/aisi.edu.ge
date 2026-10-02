<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parent–teacher meetings (ფაზა C "appointments"): a teacher publishes
 * time slots, a guardian books one for their own child with one of that
 * child's teachers. Capacity is enforced under a row lock on the slot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('starts_at');
            $table->unsignedSmallInteger('duration_minutes');
            $table->string('location')->nullable();
            $table->unsignedSmallInteger('capacity')->default(1);
            $table->string('status')->default('open'); // open | cancelled
            $table->timestamps();

            $table->index(['tenant_id', 'teacher_id', 'starts_at'], 'appointment_slots_teacher_time_idx');
        });

        Schema::create('appointment_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_slot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guardian_id')->constrained('users')->cascadeOnDelete();
            $table->text('note')->nullable();
            $table->string('status')->default('booked'); // booked | cancelled
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'appointment_slot_id', 'status'], 'appointment_bookings_slot_status_idx');
            $table->index(['tenant_id', 'guardian_id'], 'appointment_bookings_guardian_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_bookings');
        Schema::dropIfExists('appointment_slots');
    }
};
