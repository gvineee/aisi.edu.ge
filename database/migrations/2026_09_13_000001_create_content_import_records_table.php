<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_import_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('source_system')->default('aisi-wordpress');
            $table->string('source_key');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_type');
            $table->string('source_url');
            // Nullable morph: unset until the first successful --commit creates
            // the live Page/Post. Kept separate from Page/Post themselves so
            // those models stay free of importer-specific columns.
            $table->string('importable_type')->nullable();
            $table->unsignedBigInteger('importable_id')->nullable();
            $table->uuid('import_batch_id');
            $table->string('source_checksum')->nullable();
            $table->string('local_checksum')->nullable();
            // pending | imported | merged | archived | template_review | blocked | conflict
            $table->string('review_status')->default('pending');
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'source_system', 'source_key']);
            $table->index(['importable_type', 'importable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_import_records');
    }
};
