<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CLAUDE-PLATFORM-MODULES §4: a class announcement to families. Recipients
 * are resolved on the server when sent and snapshotted, one row per
 * guardian; a queued job delivers each one as its own private conversation
 * (families never see each other) and is idempotent per recipient row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_broadcasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('school_class_id')->constrained('school_classes')->cascadeOnDelete();
            $table->string('sender_role', 40);
            $table->string('subject');
            $table->text('body');
            $table->string('status')->default('queued'); // queued | sending | sent | partial
            $table->unsignedInteger('recipients_count')->default(0);
            $table->unsignedInteger('delivered_count')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'sender_id']);
        });

        Schema::create('message_broadcast_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_broadcast_id')->constrained('message_broadcasts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('conversations')->nullOnDelete();
            $table->string('status')->default('pending'); // pending | delivered | failed
            $table->string('failure_reason')->nullable();
            $table->timestamps();

            $table->unique(['message_broadcast_id', 'user_id'], 'msg_broadcast_recipient_uq');
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_broadcast_recipients');
        Schema::dropIfExists('message_broadcasts');
    }
};
