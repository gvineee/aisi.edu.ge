<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('ordinal');
            // Private-disk (storage/app/private) tenant-prefixed key. Bytes
            // at this path are never overwritten once written — a new
            // upload always gets a new ordinal and a new path.
            $table->string('storage_path');
            $table->string('checksum');
            $table->string('original_filename');
            $table->string('mime');
            $table->unsignedBigInteger('size');
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            // clean is a placeholder for MVP (no scan worker yet, spec §7
            // defers OCR/preview conversion); quarantined blocks
            // preview/download/approval at the application layer.
            $table->string('scan_state')->default('clean');
            $table->timestamps();

            $table->unique(['document_id', 'ordinal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_versions');
    }
};
