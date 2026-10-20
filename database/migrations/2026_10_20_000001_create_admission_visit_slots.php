<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * School visit booking (Phase 1): the admissions team publishes visit
 * times with a family capacity; a family picks one in the public visit
 * form. booked_count is changed only under a row lock, so a slot is never
 * overbooked. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admission_visit_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->unsignedSmallInteger('capacity');
            $table->unsignedSmallInteger('booked_count')->default(0);
            $table->string('label', 120)->nullable();
            $table->string('location', 160)->nullable();
            // open | cancelled
            $table->string('status', 20)->default('open');
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'starts_at'], 'visit_slots_tenant_status_start_idx');
        });

        Schema::create('admission_visit_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admission_visit_slot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admission_lead_id')->constrained()->cascadeOnDelete();
            // booked | cancelled
            $table->string('status', 20)->default('booked');
            // Set only for e-mail contacts: confirmation, reminder, cancellation.
            $table->string('notify_email', 190)->nullable();
            $table->timestamp('reminder_sent_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            // null when the family cancelled through the e-mailed link.
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['admission_visit_slot_id', 'admission_lead_id'], 'visit_bookings_slot_lead_uq');
            $table->index(['tenant_id', 'status'], 'visit_bookings_tenant_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_visit_bookings');
        Schema::dropIfExists('admission_visit_slots');
    }
};
