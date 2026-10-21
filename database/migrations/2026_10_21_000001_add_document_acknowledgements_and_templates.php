<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Document centre stage 3 (docs/07 §5, §9.3): staff acknowledgement of a
 * specific published version, and a template library. A request is tied
 * to one version; publishing a newer version supersedes it and opens a new
 * one for the same audience. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->boolean('is_template')->default(false)->after('visibility');
            // The template version a document was started from (not a foreign key, like the other version pointers).
            $table->unsignedBigInteger('template_version_id')->nullable()->after('is_template');
        });

        Schema::create('document_acknowledgement_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_version_id')->constrained()->cascadeOnDelete();
            $table->json('audience_roles');
            $table->date('due_on')->nullable();
            $table->string('note', 500)->nullable();
            // open | superseded | closed
            $table->string('status', 20)->default('open');
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'document_id', 'status'], 'doc_ack_requests_document_status_idx');
        });

        Schema::create('document_acknowledgements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // Explicit name: the generated one exceeds MySQL's 64-character identifier limit.
            $table->foreignId('document_acknowledgement_request_id')
                ->constrained('document_acknowledgement_requests', 'id', 'doc_acks_request_fk')->cascadeOnDelete();
            $table->foreignId('document_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->unique(['document_acknowledgement_request_id', 'user_id'], 'doc_acks_request_user_uq');
            $table->index(['tenant_id', 'user_id', 'acknowledged_at'], 'doc_acks_user_pending_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_acknowledgements');
        Schema::dropIfExists('document_acknowledgement_requests');
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['is_template', 'template_version_id']);
        });
    }
};
