<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance (CLAUDE-PLATFORM-MODULES §12). All money is integer minor units
 * (tetri) plus an ISO currency. Until a real provider contract exists the
 * only adapter is a clearly labelled sandbox; nothing here is
 * production-ready without accountant UAT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->char('currency', 3)->default('GEL');
            $table->timestamps();

            $table->unique(['tenant_id', 'student_id']);
        });

        Schema::create('invoice_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'fiscal_year']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('billing_account_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('fiscal_year');
            $table->string('number', 32);
            // issued | partially_paid | paid | void
            $table->string('status')->default('issued');
            $table->date('issued_on');
            $table->date('due_on');
            $table->char('currency', 3);
            $table->unsignedBigInteger('total_minor');
            $table->unsignedBigInteger('paid_minor')->default(0);
            $table->string('void_reason', 500)->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'fiscal_year', 'number'], 'invoices_tenant_year_number_uq');
            $table->index(['tenant_id', 'billing_account_id', 'status'], 'invoices_account_status_idx');
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_minor');
            $table->unsignedBigInteger('amount_minor');
            // Optional origin, e.g. a club fee.
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'invoice_id']);
        });

        Schema::create('payment_intents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            // The trusted merchant-side reference the webhook is matched by.
            $table->string('provider_reference', 64)->unique();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('status')->default('created'); // created | succeeded | failed
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'invoice_id']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('billing_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_intent_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 32);
            $table->string('provider_payment_id', 64);
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->unsignedBigInteger('unallocated_minor')->default(0);
            $table->unsignedBigInteger('refunded_minor')->default(0);
            $table->timestamp('received_at');
            $table->timestamps();

            $table->unique(['provider', 'provider_payment_id']);
            $table->index(['tenant_id', 'billing_account_id']);
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->foreignId('allocated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'invoice_id']);
            $table->index(['tenant_id', 'payment_id']);
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('reason', 500);
            $table->string('provider_refund_id', 64)->nullable()->unique();
            $table->string('status')->default('requested'); // requested | succeeded | failed
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'payment_id']);
        });

        Schema::create('provider_webhook_events', function (Blueprint $table) {
            $table->id();
            // Nullable: an event whose reference matches no trusted intent
            // has no tenant and is kept for reconciliation only.
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 32);
            $table->string('event_id', 100);
            $table->string('event_type', 60);
            $table->string('payload_hash', 64);
            $table->boolean('signature_valid');
            // processed | duplicate_ignored | rejected_signature | rejected_mismatch | unmatched | ignored_out_of_order
            $table->string('outcome', 32);
            $table->string('detail', 500)->nullable();
            $table->timestamp('received_at');
            $table->timestamps();

            $table->unique(['provider', 'event_id']);
            $table->index(['tenant_id', 'outcome']);
        });
    }

    public function down(): void
    {
        foreach (['provider_webhook_events', 'refunds', 'payment_allocations', 'payments', 'payment_intents', 'invoice_items', 'invoices', 'invoice_sequences', 'billing_accounts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
