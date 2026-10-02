<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The class's homeroom (დამრიგებელი) teacher — reviews the class's
        // report cards before they go to the director.
        Schema::table('school_classes', function (Blueprint $table) {
            $table->foreignId('homeroom_teacher_id')->nullable()->after('name')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('school_classes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('homeroom_teacher_id');
        });
    }
};
