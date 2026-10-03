<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CLAUDE-PLATFORM-MODULES §4: message attachments as a separate private
 * asset relation. Files live on the private `local` disk, are scanned
 * (scan_status) before they are served, and are downloadable only by the
 * conversation's participants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->constrained('messages')->cascadeOnDelete();
            $table->string('storage_path');
            $table->string('original_filename');
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->char('checksum', 64);
            $table->string('scan_status', 16)->default('pending');
            $table->timestamp('scanned_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_attachments');
    }
};
