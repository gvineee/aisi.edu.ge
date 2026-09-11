<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            // One of TenantMembership::ROLE_* — validated in the request/action,
            // never trusted as-is from client input.
            $table->string('role');
            // Guardian invite only: which student this invitation links to.
            $table->foreignId('student_id')->nullable()->constrained()->nullOnDelete();
            // Teacher invite only: which class (and optionally subject) this
            // invitation assigns.
            $table->foreignId('school_class_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject')->nullable();
            // Guardian permission flags, copied onto the GuardianLink created
            // when the invitation is accepted (docs/02 §5.2 — never a single
            // "is guardian" flag). Meaningless for non-guardian invitations.
            $table->boolean('can_view_academic')->default(true);
            $table->boolean('can_view_financial')->default(false);
            $table->boolean('can_pickup')->default(false);
            $table->boolean('can_receive_notifications')->default(true);
            // Single-use, time-limited token (docs/02 §5.2: "ვადიანი,
            // ერთჯერადი invitation"). Globally unique so the accept routes can
            // look it up directly; tenant isolation still comes from the
            // BelongsToTenant scope plus an explicit controller check, never
            // from the token's uniqueness alone.
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('invited_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'email']);
            $table->index(['tenant_id', 'accepted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_invitations');
    }
};
