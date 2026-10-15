<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bookkeeping without a bank API: payments recorded by the accountant
 * (cash / bank transfer / POS), reversal of a wrongly entered payment,
 * fee plans with installments, per-student fee contracts, idempotent
 * installment charges, overdue reminders and the school's payment
 * instructions. Additive only — existing finance rows keep working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // null for provider (webhook) payments.
            $table->string('method', 20)->nullable()->after('provider_payment_id');
            $table->string('reference', 100)->nullable()->after('method');
            $table->string('note', 500)->nullable()->after('reference');
            $table->foreignId('recorded_by')->nullable()->after('note')->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('reversed_minor')->default(0)->after('refunded_minor');
            $table->timestamp('reversed_at')->nullable()->after('reversed_minor');
            $table->string('reversal_reason', 500)->nullable()->after('reversed_at');

            $table->index(['tenant_id', 'method', 'reference'], 'payments_method_reference_idx');
            $table->index(['tenant_id', 'received_at'], 'payments_received_idx');
        });

        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->timestamp('reversed_at')->nullable()->after('allocated_by');
        });

        Schema::table('refunds', function (Blueprint $table) {
            $table->string('method', 20)->nullable()->after('status');
        });

        Schema::create('fee_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->char('currency', 3)->default('GEL');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('fee_plan_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fee_plan_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->unsignedBigInteger('amount_minor');
            // The day the invoice is issued automatically, and its due date.
            $table->date('issue_on');
            $table->date('due_on');
            $table->timestamps();

            $table->index(['tenant_id', 'issue_on']);
        });

        Schema::create('student_fee_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fee_plan_id')->constrained()->cascadeOnDelete();
            $table->string('contract_number', 64)->nullable();
            $table->date('signed_on')->nullable();
            $table->unsignedTinyInteger('discount_percent')->default(0);
            $table->string('discount_reason', 255)->nullable();
            // active | ended
            $table->string('status', 16)->default('active');
            $table->date('ended_on')->nullable();
            $table->string('notes', 1000)->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'student_id', 'fee_plan_id'], 'fee_contracts_student_plan_uq');
            $table->index(['tenant_id', 'fee_plan_id', 'status'], 'fee_contracts_plan_status_idx');
        });

        Schema::create('fee_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_fee_contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fee_plan_installment_id')->constrained()->cascadeOnDelete();
            // null when the installment was fully waived (100% discount).
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['student_fee_contract_id', 'fee_plan_installment_id'], 'fee_charges_contract_installment_uq');
        });

        Schema::create('invoice_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('recipients');
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->index(['tenant_id', 'invoice_id']);
        });

        Schema::create('finance_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('recipient_name')->nullable();
            $table->string('recipient_code', 32)->nullable();
            // [{bank: string, iban: string}]
            $table->json('bank_accounts')->nullable();
            $table->string('instructions', 2000)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_settings');
        Schema::dropIfExists('invoice_reminders');
        Schema::dropIfExists('fee_charges');
        Schema::dropIfExists('student_fee_contracts');
        Schema::dropIfExists('fee_plan_installments');
        Schema::dropIfExists('fee_plans');

        Schema::table('refunds', fn (Blueprint $table) => $table->dropColumn('method'));
        Schema::table('payment_allocations', fn (Blueprint $table) => $table->dropColumn('reversed_at'));
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_method_reference_idx');
            $table->dropIndex('payments_received_idx');
            $table->dropConstrainedForeignId('recorded_by');
            $table->dropColumn(['method', 'reference', 'note', 'reversed_minor', 'reversed_at', 'reversal_reason']);
        });
    }
};
