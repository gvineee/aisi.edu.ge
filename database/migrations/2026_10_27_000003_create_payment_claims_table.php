<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A guardian's "I have paid" notice for an invoice (bank transfer or cash
 * at the school), until the accountant confirms it against the bank
 * statement — confirming books an ordinary manual payment. Nothing here
 * marks an invoice paid by itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('submitted_by')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('method', 32);
            $table->date('paid_on');
            $table->string('reference', 120)->nullable();
            $table->string('note', 500)->nullable();
            $table->string('status', 16)->default('pending'); // pending | confirmed | rejected
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'status'], 'payment_claims_tenant_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_claims');
    }
};
