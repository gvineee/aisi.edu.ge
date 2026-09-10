<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('page_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('excerpt')->nullable();
            $table->json('blocks');
            $table->string('status');
            $table->string('seo_title')->nullable();
            $table->string('seo_description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // Revisions are immutable snapshots — created_at only, no updated_at.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'page_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_revisions');
    }
};
