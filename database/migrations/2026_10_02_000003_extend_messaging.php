<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messaging completion (CLAUDE-PLATFORM-MODULES §4): per-participant
 * archive, "needs response" action requests, notification preferences and
 * a channel/status on deliveries so an email notification is attempted at
 * most once per recipient (idempotent) with its outcome recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_participants', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable();
        });

        Schema::table('message_deliveries', function (Blueprint $table) {
            $table->string('channel', 16)->default('in_app');
            $table->string('status', 16)->default('delivered'); // delivered | queued | sent | failed
            $table->string('failure_reason', 255)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
        });

        // New key first (it also serves the message_id foreign key on
        // MySQL), then drop the old one.
        Schema::table('message_deliveries', function (Blueprint $table) {
            $table->unique(['message_id', 'recipient_id', 'channel'], 'message_deliveries_msg_recipient_channel_uq');
        });
        Schema::table('message_deliveries', function (Blueprint $table) {
            $table->dropUnique(['message_id', 'recipient_id']);
        });

        Schema::create('action_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('target_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 16)->default('reply'); // reply | acknowledge
            $table->timestamp('due_at')->nullable();
            $table->string('status', 16)->default('open'); // open | done
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'target_user_id', 'status'], 'action_requests_target_status_idx');
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category', 32); // messages
            $table->string('channel', 16); // email
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id', 'category', 'channel'], 'notification_prefs_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('action_requests');

        Schema::table('message_deliveries', function (Blueprint $table) {
            $table->unique(['message_id', 'recipient_id']);
        });
        Schema::table('message_deliveries', function (Blueprint $table) {
            $table->dropUnique('message_deliveries_msg_recipient_channel_uq');
            $table->dropColumn(['channel', 'status', 'failure_reason', 'attempts']);
        });

        Schema::table('conversation_participants', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
    }
};
