<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/02 §5.5: class book lists, and library circulation — physical
 * copies, loans with due dates, reservations and availability. One copy is
 * in at most one active loan, enforced by a unique active_key (copy id
 * while active, null once returned/lost) — same pattern as substitution
 * assignments. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library_copies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('library_resource_id')->constrained()->cascadeOnDelete();
            $table->string('inventory_number', 40);
            // available | on_loan | on_hold | lost | withdrawn
            $table->string('status', 20)->default('available');
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'inventory_number'], 'library_copies_tenant_inventory_uq');
            $table->index(['tenant_id', 'library_resource_id', 'status'], 'library_copies_resource_status_idx');
        });

        Schema::create('library_loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('library_copy_id')->constrained()->cascadeOnDelete();
            // Exactly one of student_id / user_id (a staff borrower).
            $table->foreignId('student_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->date('due_on');
            $table->unsignedTinyInteger('renewals')->default(0);
            // active | returned | lost
            $table->string('status', 20)->default('active');
            $table->unsignedBigInteger('active_key')->nullable()->unique('library_loans_active_copy_uq');
            $table->timestamp('returned_at')->nullable();
            $table->foreignId('lent_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reminder_sent_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'due_on'], 'library_loans_status_due_idx');
            $table->index(['tenant_id', 'student_id'], 'library_loans_student_idx');
        });

        Schema::create('library_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('library_resource_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            // waiting | ready | fulfilled | cancelled | expired
            $table->string('status', 20)->default('waiting');
            // One open (waiting/ready) reservation per student and title.
            $table->string('open_key', 64)->nullable()->unique('library_reservations_open_uq');
            $table->foreignId('library_copy_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('ready_until')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'library_resource_id', 'status'], 'library_reservations_queue_idx');
        });

        Schema::create('book_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('school_class_id')->constrained()->cascadeOnDelete();
            $table->foreignId('library_resource_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 255);
            $table->string('author', 255)->nullable();
            $table->string('edition', 120)->nullable();
            $table->string('isbn', 20)->nullable();
            $table->string('subject', 120)->nullable();
            // required | optional
            $table->string('requirement', 20)->default('required');
            // purchase | loan | digital
            $table->string('acquisition', 20)->default('purchase');
            $table->string('note', 500)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'school_class_id', 'position'], 'book_list_items_class_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_list_items');
        Schema::dropIfExists('library_reservations');
        Schema::dropIfExists('library_loans');
        Schema::dropIfExists('library_copies');
    }
};
