<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consent_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('consent_form_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            // The guardian (users.id) who last responded on this child's
            // behalf. Not a foreign key to guardian_links — that link can be
            // revoked later without invalidating the historical response.
            $table->foreignId('guardian_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('responded_at')->nullable();
            $table->boolean('granted')->nullable();
            $table->timestamps();

            // One response row per child per form — responding again updates
            // this row rather than creating a second one (docs brief: "respond
            // again should update, not duplicate").
            $table->unique(['consent_form_id', 'student_id']);
            $table->index(['tenant_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consent_responses');
    }
};
