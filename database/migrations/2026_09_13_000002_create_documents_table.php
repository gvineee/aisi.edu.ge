<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained('document_workspaces')->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('responsible_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type');
            $table->string('title');
            // Intentionally NOT a foreign key to document_versions: that
            // table references documents.id, so constraining these the
            // other way round would create a circular dependency at
            // create-table time. Integrity is enforced in the Actions
            // (CreateDraftDocument/UploadDocumentVersion/DecideApproval),
            // which are the only writers of these two columns.
            $table->unsignedBigInteger('latest_version_id')->nullable();
            $table->unsignedBigInteger('published_version_id')->nullable();
            // draft -> in_review -> (changes_requested -> draft) | approved
            $table->string('status')->default('draft');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
