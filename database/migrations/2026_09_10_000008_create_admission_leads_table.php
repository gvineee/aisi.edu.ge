<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admission_leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('guardian_name');
            $table->string('contact_method'); // email | phone
            $table->string('contact_value');
            $table->string('desired_grade')->nullable();
            $table->string('preferred_date')->nullable();
            $table->boolean('consent_given')->default(false);
            // new -> contacted -> visit -> application -> review -> offer -> enrolled | closed
            $table->string('stage')->default('new');
            $table->timestamp('duplicate_of_checked_at')->nullable();
            $table->foreignId('duplicate_of_lead_id')->nullable()->constrained('admission_leads')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'contact_value']);
            $table->index(['tenant_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_leads');
    }
};
