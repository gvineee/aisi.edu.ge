<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A student was not previously a portal login at all (see the doc-comment
 * on Student). This links a Student record to the User who logs in as that
 * student — nullable, since most Student rows still have no portal account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('school_class_id')->constrained()->nullOnDelete();
            $table->unique(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'user_id']);
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
