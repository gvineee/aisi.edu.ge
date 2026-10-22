<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Document centre stage 4 (docs/07 §4, §9.4): multi-step review per
 * workspace (sequential or all-required), review due dates with reminders,
 * and time-limited, audited reviewer delegation. Workspaces default to the
 * existing single-reviewer flow. Additive only; identifiers are named
 * explicitly where the generated ones would exceed MySQL's 64 characters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_workspaces', function (Blueprint $table) {
            // single | sequential | all
            $table->string('review_mode', 20)->default('single')->after('requires_approval');
            $table->unsignedSmallInteger('review_days')->nullable()->after('review_mode');
        });

        Schema::create('document_workspace_reviewers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained('document_workspaces', 'id', 'doc_ws_reviewers_ws_fk')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users', 'id', 'doc_ws_reviewers_user_fk')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->timestamps();

            $table->unique(['workspace_id', 'user_id'], 'doc_ws_reviewers_uq');
        });

        Schema::create('approval_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('approval_request_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->foreignId('reviewer_id')->constrained('users')->cascadeOnDelete();
            // pending | approved | returned | skipped | cancelled
            $table->string('state', 20)->default('pending');
            $table->timestamp('acted_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamps();

            $table->unique(['approval_request_id', 'position'], 'approval_steps_request_position_uq');
            $table->index(['tenant_id', 'reviewer_id', 'state'], 'approval_steps_reviewer_state_idx');
        });

        Schema::table('approval_decisions', function (Blueprint $table) {
            $table->foreignId('approval_step_id')->nullable()->after('approval_request_id')->constrained()->nullOnDelete();
            // Set when a delegate decided for the step's reviewer.
            $table->foreignId('on_behalf_of_id')->nullable()->after('reviewer_id')->constrained('users')->nullOnDelete();
        });

        Schema::create('document_review_delegations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('delegator_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('delegate_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('reason', 500);
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'delegate_id', 'ends_at'], 'doc_review_delegations_delegate_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_review_delegations');
        Schema::table('approval_decisions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approval_step_id');
            $table->dropConstrainedForeignId('on_behalf_of_id');
        });
        Schema::dropIfExists('approval_steps');
        Schema::dropIfExists('document_workspace_reviewers');
        Schema::table('document_workspaces', function (Blueprint $table) {
            $table->dropColumn(['review_mode', 'review_days']);
        });
    }
};
