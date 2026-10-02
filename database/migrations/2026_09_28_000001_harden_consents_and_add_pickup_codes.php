<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CLAUDE-PLATFORM-MODULES §7 hardening of the Pickup & Consent module:
 *  - consent forms carry a checksum of the exact text and an optional
 *    response cutoff; every guardian decision is kept as an append-only
 *    event (the old table stays as the "current answer" per child);
 *  - one-time pickup codes (stored only as an HMAC), and a pickup event
 *    log covering both code-verified and override releases.
 * Additive only: existing rows are backfilled, nothing is dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consent_forms', function (Blueprint $table) {
            $table->string('body_checksum', 64)->nullable()->after('body');
            $table->timestamp('responses_close_at')->nullable()->after('requires_signature');
        });

        foreach (DB::table('consent_forms')->select('id', 'title', 'body')->get() as $form) {
            DB::table('consent_forms')->where('id', $form->id)->update([
                'body_checksum' => hash('sha256', $form->title."\n".$form->body),
            ]);
        }

        Schema::create('consent_response_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('consent_form_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guardian_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('guardian_link_id')->nullable()->constrained('guardian_links')->nullOnDelete();
            $table->string('decision', 16); // granted | declined | revoked
            // The exact form text version the guardian answered.
            $table->string('form_checksum', 64);
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'consent_form_id', 'student_id'], 'consent_events_form_student_idx');
        });

        foreach (DB::table('consent_responses')->whereNotNull('granted')->get() as $response) {
            $checksum = DB::table('consent_forms')->where('id', $response->consent_form_id)->value('body_checksum');
            DB::table('consent_response_events')->insert([
                'tenant_id' => $response->tenant_id,
                'consent_form_id' => $response->consent_form_id,
                'student_id' => $response->student_id,
                'guardian_id' => $response->guardian_id,
                'decision' => $response->granted ? 'granted' : 'declined',
                'form_checksum' => (string) $checksum,
                'created_at' => $response->responded_at ?? $response->updated_at,
            ]);
        }

        Schema::create('pickup_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            // null = the issuing guardian collects the child themselves.
            $table->foreignId('authorized_pickup_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('issued_by')->constrained('users')->cascadeOnDelete();
            // HMAC of the code; the code itself is shown once and never stored.
            $table->string('code_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->foreignId('consumed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'student_id', 'expires_at'], 'pickup_codes_student_exp_idx');
        });

        Schema::create('pickup_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pickup_code_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method', 16); // code | override
            $table->string('released_to');
            $table->text('reason')->nullable(); // override only
            $table->foreignId('staff_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'student_id', 'created_at'], 'pickup_events_student_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pickup_events');
        Schema::dropIfExists('pickup_codes');
        Schema::dropIfExists('consent_response_events');

        Schema::table('consent_forms', function (Blueprint $table) {
            $table->dropColumn(['body_checksum', 'responses_close_at']);
        });
    }
};
