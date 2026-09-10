<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_access_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // Polymorphic-by-hand rather than morphs(): only ever
            // 'workspace' or 'document' this stage, and an explicit string
            // is easier to audit than a class-name morph type.
            $table->string('resource_type');
            $table->unsignedBigInteger('resource_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // 'contributor' (can create/edit drafts here beyond their own)
            // or 'approver' (can decide approvals here beyond the director
            // role default) — a grant is additive, never a restriction.
            $table->string('permission');
            $table->timestamps();

            $table->unique(['tenant_id', 'resource_type', 'resource_id', 'user_id', 'permission'], 'document_access_grants_unique_grant');
            $table->index(['tenant_id', 'resource_type', 'resource_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_access_grants');
    }
};
