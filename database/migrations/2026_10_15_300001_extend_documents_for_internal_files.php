<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Internal staff documents (contracts, internal paperwork): workspace
 * settings, tenant-defined categories, document metadata and role-or-user
 * access grants. Additive only — every new column has a default that keeps
 * the existing behaviour (all-staff workspace, approval required, no
 * category), so rows already in production are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_workspaces', function (Blueprint $table) {
            $table->text('description')->nullable()->after('title');
            // all_staff: every staff member can read published files and add
            // their own; restricted: only oversight + explicit grants.
            $table->string('visibility', 20)->default('all_staff')->after('classification');
            $table->boolean('requires_approval')->default(true)->after('visibility');
            // Downloads from a confidential workspace are written to the audit log.
            $table->boolean('is_confidential')->default(false)->after('requires_approval');
            $table->timestamp('archived_at')->nullable()->after('created_by');
        });

        Schema::create('document_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('workspace_id')->constrained('document_categories')->nullOnDelete();
            $table->text('description')->nullable()->after('title');
            // The date the document itself carries (contract signing date,
            // order date) — distinct from the upload timestamp.
            $table->date('document_date')->nullable()->after('description');
            $table->date('expires_on')->nullable()->after('document_date');
            $table->json('tags')->nullable()->after('expires_on');
            // workspace: inherits the workspace's audience; restricted: only
            // the owner, oversight, workspace managers and explicit grants.
            $table->string('visibility', 20)->default('workspace')->after('status');

            $table->index(['tenant_id', 'document_date'], 'documents_tenant_document_date_idx');
            $table->index(['tenant_id', 'expires_on'], 'documents_tenant_expires_on_idx');
        });

        Schema::table('document_access_grants', function (Blueprint $table) {
            // A grant targets either one user or everyone holding a role.
            $table->foreignId('user_id')->nullable()->change();
            $table->string('role', 40)->nullable()->after('user_id');
            $table->foreignId('granted_by')->nullable()->after('permission')->constrained('users')->nullOnDelete();

            $table->unique(['tenant_id', 'resource_type', 'resource_id', 'role', 'permission'], 'document_access_grants_unique_role_grant');
        });
    }

    public function down(): void
    {
        Schema::table('document_access_grants', function (Blueprint $table) {
            $table->dropUnique('document_access_grants_unique_role_grant');
            $table->dropConstrainedForeignId('granted_by');
            $table->dropColumn('role');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex('documents_tenant_document_date_idx');
            $table->dropIndex('documents_tenant_expires_on_idx');
            $table->dropConstrainedForeignId('category_id');
            $table->dropColumn(['description', 'document_date', 'expires_on', 'tags', 'visibility']);
        });

        Schema::dropIfExists('document_categories');

        Schema::table('document_workspaces', function (Blueprint $table) {
            $table->dropColumn(['description', 'visibility', 'requires_approval', 'is_confidential', 'archived_at']);
        });
    }
};
