<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Appointment reminders: `reminder_sent_at` is claimed atomically by the
 * scheduled `appointments:send-reminders` command, so each booking is
 * reminded at most once even if two scheduler runs overlap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointment_bookings', function (Blueprint $table) {
            $table->timestamp('reminder_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('appointment_bookings', function (Blueprint $table) {
            $table->dropColumn('reminder_sent_at');
        });
    }
};
