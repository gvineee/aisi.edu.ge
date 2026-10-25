<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The images (inline and gallery, in source order) and file attachments of
 * an imported page or post, each pinned to its original file: source URL,
 * WordPress media id, checksum, MIME, size, alt and caption. The body text
 * is untouched; this is the part plain-text import could not carry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_media_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('linkable_type', 191);
            $table->unsignedBigInteger('linkable_id');
            $table->string('role', 16); // image | attachment
            $table->string('group_key', 32)->nullable(); // gallery-1 …; null = inline
            $table->unsignedSmallInteger('position');
            $table->string('disk_path', 512);
            $table->string('mime', 127);
            $table->unsignedBigInteger('bytes');
            $table->char('sha256', 64);
            $table->text('source_url');
            $table->unsignedBigInteger('source_media_id')->nullable();
            $table->string('resolution', 16); // original | variant_only
            $table->string('alt', 500)->nullable();
            $table->text('caption')->nullable();
            $table->string('label', 500)->nullable();
            $table->timestamps();
            $table->unique(['linkable_type', 'linkable_id', 'role', 'position'], 'content_media_links_position_unique');
            $table->index(['tenant_id', 'linkable_type', 'linkable_id'], 'content_media_links_owner_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_media_links');
    }
};
