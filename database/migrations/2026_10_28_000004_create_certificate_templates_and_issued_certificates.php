<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Certificates, diplomas and letters: templates with placeholders, and the
 * register of documents issued from them. An issued document keeps a fixed
 * snapshot of its text (its PDF is rendered from that, never stored), a
 * yearly number and a public verification code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificate_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('kind', 16); // certificate | diploma | letter
            $table->string('title', 200);
            $table->text('body');
            $table->string('signatory', 160)->nullable();
            $table->string('orientation', 10)->default('portrait');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('issued_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('certificate_template_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('student_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('sequence');
            $table->string('verification_code', 16)->unique();
            $table->json('snapshot');
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revoke_reason', 500)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'year', 'sequence'], 'issued_certificates_number_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issued_certificates');
        Schema::dropIfExists('certificate_templates');
    }
};
