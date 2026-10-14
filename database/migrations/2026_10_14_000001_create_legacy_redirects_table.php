<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Old-site URL → new-site URL mapping (CLAUDE.md "მიგრაცია და გაშვება":
 * keep the legacy URL inventory and redirect mapping). Looked up only when a
 * request would otherwise 404, keyed by a hash of the normalised path so
 * long percent-decoded Georgian slugs never hit MySQL index-length limits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_redirects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->text('source_path');
            $table->char('source_hash', 64);
            $table->string('target_path', 512);
            $table->unsignedSmallInteger('status_code')->default(301);
            $table->string('origin', 16)->default('manual');
            $table->unsignedBigInteger('hits')->default(0);
            $table->timestamp('last_hit_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'source_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_redirects');
    }
};
