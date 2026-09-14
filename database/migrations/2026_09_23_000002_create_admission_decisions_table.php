<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admission_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // One decision per lead: a lead is decided exactly once in this
            // simple pipeline — no "re-decide" flow in this pass.
            $table->foreignId('admission_lead_id')->unique()->constrained('admission_leads')->cascadeOnDelete();
            $table->string('decision'); // accepted | declined | waitlisted
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'decision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_decisions');
    }
};
