<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guardian_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            // Separate, independently revocable permissions (docs/02 §5.2) —
            // never a single "is guardian" flag.
            $table->boolean('can_view_academic')->default(true);
            $table->boolean('can_view_financial')->default(false);
            $table->boolean('can_pickup')->default(false);
            $table->boolean('can_receive_notifications')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id', 'student_id']);
            $table->index(['tenant_id', 'student_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guardian_links');
    }
};
