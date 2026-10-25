<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bookkeeping for school-data imports (EduPage first): every run is a
 * batch with the file's checksum and counts, every imported source record
 * is pinned to the row it created, and anything that could not be imported
 * is quarantined as an issue. Issues hold source keys and field names only,
 * never the raw (possibly personal) values.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('source', 32); // edupage
            $table->string('type', 32); // classes | subjects | rooms | staff | timetable-summary
            $table->foreignId('academic_year_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mode', 16); // dry_run | apply
            $table->string('status', 16); // previewed | applied | failed
            $table->string('file_name');
            $table->char('file_sha256', 64);
            $table->json('context')->nullable(); // export scope / version / validity as given
            $table->json('counts')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'source', 'type'], 'import_batches_source_type_idx');
        });

        Schema::create('import_identity_maps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('source', 32);
            $table->string('entity_type', 32);
            // 0 = not year-scoped (staff, subjects, rooms); otherwise academic_years.id.
            $table->unsignedBigInteger('scope_id')->default(0);
            $table->string('source_key', 191);
            $table->string('target_type', 191);
            $table->unsignedBigInteger('target_id');
            $table->char('source_hash', 64);
            $table->timestamp('synced_at');
            $table->foreignId('first_batch_id')->nullable()->constrained('import_batches')->nullOnDelete();
            $table->foreignId('last_batch_id')->nullable()->constrained('import_batches')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'source', 'entity_type', 'scope_id', 'source_key'], 'import_identity_maps_source_unique');
            $table->index(['tenant_id', 'target_type', 'target_id'], 'import_identity_maps_target_idx');
        });

        Schema::create('import_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('import_batch_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number')->nullable();
            $table->string('source_key', 191)->nullable();
            $table->string('kind', 32); // unresolved_reference | duplicate | validation | conflict | needs_invitation | unmapped
            $table->string('field', 64)->nullable();
            $table->string('message', 500);
            $table->timestamps();
            $table->index(['tenant_id', 'import_batch_id', 'kind'], 'import_issues_batch_kind_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_issues');
        Schema::dropIfExists('import_identity_maps');
        Schema::dropIfExists('import_batches');
    }
};
