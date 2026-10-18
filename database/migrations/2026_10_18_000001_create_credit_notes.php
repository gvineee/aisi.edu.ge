<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credit notes: the accountant's correction of an issued (possibly already
 * paid) invoice. A credit note lowers what the invoice asks for; money
 * already paid above the new amount goes back to account credit. Numbered
 * per tenant and fiscal year separately from invoices. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('credited_minor')->default(0)->after('paid_minor');
        });

        Schema::create('credit_note_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'fiscal_year']);
        });

        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('billing_account_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('fiscal_year');
            $table->string('number', 32);
            $table->date('issued_on');
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            // Part of the amount that was already paid and went back to account credit.
            $table->unsignedBigInteger('released_minor')->default(0);
            $table->string('reason', 500);
            // Same form submission never issues a second credit note.
            $table->string('idempotency_key', 64);
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'fiscal_year', 'number'], 'credit_notes_tenant_year_number_uq');
            $table->unique(['tenant_id', 'idempotency_key'], 'credit_notes_tenant_idempotency_uq');
            $table->index(['tenant_id', 'invoice_id']);
            $table->index(['tenant_id', 'billing_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_notes');
        Schema::dropIfExists('credit_note_sequences');
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('credited_minor');
        });
    }
};
