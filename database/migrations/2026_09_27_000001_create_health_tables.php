<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Health record (CLAUDE-PLATFORM-MODULES §8). Every column holding a
 * condition, medication, note, contact name/phone or incident body is
 * stored as Laravel-encrypted text (application-level encryption with
 * APP_KEY), so it is unreadable in DB dumps and query logs. Only
 * non-sensitive operational fields (severity, flags, timestamps, ids) stay
 * in plain columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_permission_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // health.view | health.manage | health.incident.create | health.audit.view
            $table->string('capability', 40);
            $table->foreignId('granted_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'user_id', 'capability'], 'health_grants_user_cap_idx');
        });

        Schema::create('student_health_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('family_doctor')->nullable(); // encrypted
            $table->text('notes')->nullable(); // encrypted
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'student_id'], 'health_profiles_tenant_student_idx');
        });

        Schema::create('health_conditions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->text('name'); // encrypted
            $table->string('severity', 16); // low | medium | high
            $table->text('action_plan')->nullable(); // encrypted
            // Short, actionable line shown to the student's teachers (who do
            // not get the full record) — e.g. what to do, not a diagnosis.
            $table->boolean('show_alert_to_staff')->default(false);
            $table->text('staff_alert')->nullable(); // encrypted
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'student_id'], 'health_conditions_tenant_student_idx');
        });

        Schema::create('medications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->text('name'); // encrypted
            // Exactly as provided by the family/doctor — the system never
            // suggests or computes a dose.
            $table->text('instructions')->nullable(); // encrypted
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'student_id'], 'medications_tenant_student_idx');
        });

        Schema::create('emergency_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->text('name'); // encrypted
            $table->text('relationship')->nullable(); // encrypted
            $table->text('phone'); // encrypted
            $table->unsignedTinyInteger('priority')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'student_id'], 'emergency_contacts_tenant_student_idx');
        });

        Schema::create('care_plan_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->text('title'); // encrypted
            $table->string('storage_path');
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->string('checksum', 64);
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'student_id'], 'care_plan_documents_tenant_student_idx');
        });

        Schema::create('health_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->timestamp('occurred_at');
            $table->text('summary'); // encrypted
            $table->text('action_taken')->nullable(); // encrypted
            $table->timestamp('guardian_notified_at')->nullable();
            $table->foreignId('reported_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'student_id', 'occurred_at'], 'health_incidents_student_time_idx');
        });

        Schema::create('health_emergency_accesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('reason'); // encrypted
            $table->timestamp('expires_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable(); // encrypted
            $table->timestamps();

            $table->index(['tenant_id', 'student_id', 'user_id'], 'health_emergency_student_user_idx');
            $table->index(['tenant_id', 'reviewed_at'], 'health_emergency_review_idx');
        });

        // Who looked at / changed which student's health data, and how.
        // Never stores the data itself.
        Schema::create('health_access_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $table->string('action', 40);
            // full | alerts_only | guardian | emergency
            $table->string('access_basis', 20);
            $table->foreignId('emergency_access_id')->nullable()->constrained('health_emergency_accesses')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'student_id', 'created_at'], 'health_access_student_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_access_events');
        Schema::dropIfExists('health_emergency_accesses');
        Schema::dropIfExists('health_incidents');
        Schema::dropIfExists('care_plan_documents');
        Schema::dropIfExists('emergency_contacts');
        Schema::dropIfExists('medications');
        Schema::dropIfExists('health_conditions');
        Schema::dropIfExists('student_health_profiles');
        Schema::dropIfExists('health_permission_grants');
    }
};
