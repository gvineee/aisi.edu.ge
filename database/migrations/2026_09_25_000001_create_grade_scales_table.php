<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A grade scale is immutable once created: changing the school's
        // grading rules means creating a new version, so every assessment
        // keeps pointing at the exact bands it was graded under.
        Schema::create('grade_scales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('version');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'version']);
            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('grade_scale_bands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('grade_scale_id')->constrained()->cascadeOnDelete();
            // Lowest normalized percent (inclusive) that earns this label.
            $table->decimal('min_percent', 5, 2);
            $table->string('label', 32);
            $table->timestamps();

            $table->unique(['grade_scale_id', 'min_percent']);
            $table->index(['tenant_id', 'grade_scale_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_scale_bands');
        Schema::dropIfExists('grade_scales');
    }
};
