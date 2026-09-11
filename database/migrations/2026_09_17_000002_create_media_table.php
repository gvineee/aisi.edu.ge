<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // Always 'public' today (CMS images/PDFs are meant to end up on
            // published public pages, same as brand logo/hero assets) — kept
            // as an explicit column rather than a hardcoded assumption so a
            // future private-media workflow doesn't need a schema change.
            $table->string('disk')->default('public');
            $table->string('path');
            $table->string('mime');
            $table->unsignedBigInteger('size');
            $table->string('original_filename');
            $table->string('alt_text')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
