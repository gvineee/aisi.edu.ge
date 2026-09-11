<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            // The school's own roster identifier for this student (Georgian
            // personal number in practice). Nullable — a school may not have
            // collected it yet for every existing record — but unique per
            // tenant once set, since it is the primary key a self-registering
            // guardian/student is matched against.
            $table->string('national_id')->nullable()->after('last_name');
            $table->unique(['tenant_id', 'national_id']);
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'national_id']);
            $table->dropColumn('national_id');
        });
    }
};
