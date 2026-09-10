<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('author')->nullable();
            $table->string('isbn')->nullable();
            $table->string('grade')->nullable();
            $table->string('subject')->nullable();
            $table->boolean('is_required')->default(false);
            // catalog_only | loan | digital — see docs/02 section 5.5. A
            // public catalog entry never implies a right to redistribute the
            // file; that's a separate, policy-checked private download.
            $table->string('access_scope')->default('catalog_only');
            $table->string('external_url')->nullable();
            $table->string('file_path')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'grade', 'subject']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_resources');
    }
};
