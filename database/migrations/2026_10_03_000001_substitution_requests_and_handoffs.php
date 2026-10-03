<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CLAUDE-PLATFORM-MODULES §9: race-safe substitute assignment, teacher
 * coverage requests and lesson handoffs.
 *
 * `active_key` is "{lesson_id}:{date}" while an assignment is active and
 * NULL once cancelled, so a unique index lets the database itself reject a
 * second concurrent assignment of the same lesson occurrence (NULLs are
 * not compared by unique indexes in PostgreSQL, MySQL or SQLite).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('substitution_assignments', function (Blueprint $table) {
            $table->string('active_key', 64)->nullable();
        });

        // Backfill active rows; if an earlier race left two active rows for
        // one occurrence, only the oldest keeps the key (the other stays
        // visible for an admin to cancel).
        $seen = [];
        foreach (DB::table('substitution_assignments')->where('status', 'assigned')->orderBy('id')->get(['id', 'tenant_id', 'lesson_id', 'date']) as $row) {
            $key = $row->lesson_id.':'.substr((string) $row->date, 0, 10);
            if (isset($seen[$row->tenant_id.'|'.$key])) {
                continue;
            }
            $seen[$row->tenant_id.'|'.$key] = true;
            DB::table('substitution_assignments')->where('id', $row->id)->update(['active_key' => $key]);
        }

        Schema::table('substitution_assignments', function (Blueprint $table) {
            $table->unique(['tenant_id', 'active_key'], 'substitution_assignments_active_uq');
        });

        Schema::create('substitution_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requester_id')->constrained('users')->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('reason', 500)->nullable();
            $table->string('status')->default('pending'); // pending | approved | declined | withdrawn
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->foreignId('absence_id')->nullable()->constrained('staff_absences')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'requester_id']);
        });

        Schema::create('lesson_handoffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('substitution_assignment_id')->constrained('substitution_assignments')->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('plan');
            $table->text('materials')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['substitution_assignment_id'], 'lesson_handoffs_assignment_uq');
            $table->index(['tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_handoffs');
        Schema::dropIfExists('substitution_requests');
        Schema::table('substitution_assignments', function (Blueprint $table) {
            $table->dropUnique('substitution_assignments_active_uq');
            $table->dropColumn('active_key');
        });
    }
};
