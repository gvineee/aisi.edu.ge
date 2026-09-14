<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Defensive against a specific production incident: an earlier
        // deploy of this migration hit MySQL's 64-char identifier limit on
        // the auto-generated name for the first index below, which aborted
        // the migration *after* Schema::create had already run (MySQL adds
        // explicit indexes as separate ALTER statements following the
        // CREATE TABLE). That left the table created — columns and foreign
        // keys intact — with zero of its three indexes, and the migration
        // itself unmarked as run. Re-running plain Schema::create against
        // that table would fail with "table already exists", so we detect
        // that exact partial state and finish only the missing indexes
        // instead of recreating the table. See
        // docs/decisions/0001-stack-and-versions.md for the same class of
        // MySQL identifier-length issue found and fixed previously.
        if (Schema::hasTable('substitution_assignments')) {
            $this->addMissingIndexes();

            return;
        }

        Schema::create('substitution_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            // Nullable: an admin may assign coverage for a single lesson
            // without first (or ever) filing a staff_absences row.
            $table->foreignId('absence_id')->nullable()->constrained('staff_absences')->nullOnDelete();
            $table->foreignId('absent_teacher_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('substitute_teacher_id')->constrained('users')->cascadeOnDelete();
            // Calendar date this specific assignment covers — lessons are a
            // recurring weekly template (day_of_week + time), so the same
            // lesson_id needs one row per covered date.
            $table->date('date');
            $table->string('status')->default('assigned'); // assigned | cancelled
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Explicit short name: MySQL's 64-char identifier limit rejects
            // the auto-generated name for this column combination (see
            // docs/decisions/0001-stack-and-versions.md).
            $table->index(['tenant_id', 'substitute_teacher_id', 'date'], 'substitution_assignments_substitute_date_idx');
            $table->index(['tenant_id', 'lesson_id', 'date']);
            $table->index(['tenant_id', 'absence_id']);
        });
    }

    private function addMissingIndexes(): void
    {
        Schema::table('substitution_assignments', function (Blueprint $table) {
            if (! Schema::hasIndex('substitution_assignments', 'substitution_assignments_substitute_date_idx')) {
                $table->index(['tenant_id', 'substitute_teacher_id', 'date'], 'substitution_assignments_substitute_date_idx');
            }

            if (! Schema::hasIndex('substitution_assignments', 'substitution_assignments_tenant_id_lesson_id_date_index')) {
                $table->index(['tenant_id', 'lesson_id', 'date']);
            }

            if (! Schema::hasIndex('substitution_assignments', 'substitution_assignments_tenant_id_absence_id_index')) {
                $table->index(['tenant_id', 'absence_id']);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('substitution_assignments');
    }
};
