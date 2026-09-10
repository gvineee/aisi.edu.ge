<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('initiator_id')->constrained('users')->cascadeOnDelete();
            // pending -> approved | returned | superseded (a newer version
            // was submitted before this one was decided).
            $table->string('state')->default('pending');
            $table->timestamp('due_at')->nullable();
            // Optimistic-concurrency token: DecideApproval reads this,
            // then updates WHERE lock_version = <value it read>. A second
            // concurrent decision reading the same starting value loses the
            // race with 0 affected rows instead of silently double-applying.
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->unique('document_version_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_requests');
    }
};
