<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff clock-in: when a member of staff arrived and left. Kept by the
 * person themselves, corrected by management with a reason. No location.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('work_date'); // school-local
            $table->timestamp('clock_in_at');
            $table->timestamp('clock_out_at')->nullable();
            $table->string('source', 16); // self | manual
            $table->string('note', 500)->nullable();
            $table->foreignId('corrected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('corrected_at')->nullable();
            $table->string('correction_reason', 500)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'work_date'], 'staff_time_entries_day_idx');
            $table->index(['tenant_id', 'user_id', 'clock_out_at'], 'staff_time_entries_open_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_time_entries');
    }
};
