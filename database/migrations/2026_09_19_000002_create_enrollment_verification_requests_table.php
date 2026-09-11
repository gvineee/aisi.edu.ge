<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollment_verification_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // The account that submitted this request — a guardian requests
            // access on their OWN behalf even though the identity being
            // matched (below) is their child's, not theirs.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // 'student' or 'guardian' — validated in the request/action, never
            // trusted as-is (same convention as tenant_invitations.role).
            $table->string('requested_role');
            $table->string('submitted_national_id')->nullable();
            $table->string('submitted_first_name');
            $table->string('submitted_last_name');
            // Resolved automatically at submission time by matching the
            // submitted identity against the tenant's Student roster — never
            // guessed when ambiguous (see MatchStudentForVerification). Null
            // means "no confident automatic match"; an admin must resolve it
            // manually before this request can be approved.
            $table->foreignId('matched_student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->string('status')->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_verification_requests');
    }
};
