<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Portfolio (CLAUDE-PLATFORM-MODULES §5) completion: tags, achievements,
 * export records, and a scan status on uploaded files so a pending or
 * infected file is never served (CLAUDE.md #5). Existing files predate
 * scanning and are recorded as `skipped`, not as clean.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['portfolio_assets', 'care_plan_documents'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                // pending | clean | infected | skipped (no scanning engine configured)
                $blueprint->string('scan_status', 16)->default('pending');
                $blueprint->timestamp('scanned_at')->nullable();
            });

            DB::table($table)->update(['scan_status' => 'skipped']);
        }

        Schema::create('portfolio_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('portfolio_item_id')->constrained()->cascadeOnDelete();
            $table->string('tag', 40);

            $table->unique(['portfolio_item_id', 'tag']);
            $table->index(['tenant_id', 'tag']);
        });

        Schema::create('achievements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('awarded_on');
            $table->boolean('shared_with_family')->default(true);
            $table->foreignId('awarded_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'student_id']);
        });

        Schema::create('portfolio_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('generated_by')->constrained('users')->cascadeOnDelete();
            $table->json('item_ids');
            // sha256 of the exported content — identifies the exact revision.
            $table->string('checksum', 64);
            $table->timestamp('generated_at');

            $table->index(['tenant_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_exports');
        Schema::dropIfExists('achievements');
        Schema::dropIfExists('portfolio_tags');

        foreach (['portfolio_assets', 'care_plan_documents'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn(['scan_status', 'scanned_at']);
            });
        }
    }
};
