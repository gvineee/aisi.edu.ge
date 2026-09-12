<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teachers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('slug');
            $table->string('name');
            $table->string('subject');
            $table->text('bio')->nullable();
            // Nullable on purpose — most real teachers don't have a scraped
            // photo (the original site had none), and a missing photo
            // renders as an honest initials avatar, never a fabricated one.
            $table->string('photo_path')->nullable();
            $table->unsignedInteger('display_order')->default(0);
            $table->string('status')->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'status', 'display_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teachers');
    }
};
