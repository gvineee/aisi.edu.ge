<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Days of the academic year without timetabled lessons: holidays and
 * vacations. The worked-hours report plans no lessons on them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('non_teaching_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->string('label', 120);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'academic_year_id', 'starts_on'], 'non_teaching_periods_year_start_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('non_teaching_periods');
    }
};
