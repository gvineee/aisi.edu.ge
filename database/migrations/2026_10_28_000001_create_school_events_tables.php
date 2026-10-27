<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The school calendar: events, test days, meetings, trips. An event is for
 * everyone, for staff only, or for chosen classes (school_event_classes).
 * Holidays stay in non_teaching_periods — the calendar shows them too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->string('category', 24); // event | exam | meeting | trip | other
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('starts_at', 5)->nullable(); // HH:MM school-local; null = all day
            $table->string('ends_at', 5)->nullable();
            $table->string('location', 160)->nullable();
            $table->string('audience', 16); // everyone | staff | classes
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'starts_on'], 'school_events_tenant_start_idx');
        });

        Schema::create('school_event_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('school_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('school_class_id')->constrained()->cascadeOnDelete();
            $table->unique(['school_event_id', 'school_class_id'], 'school_event_classes_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_event_classes');
        Schema::dropIfExists('school_events');
    }
};
