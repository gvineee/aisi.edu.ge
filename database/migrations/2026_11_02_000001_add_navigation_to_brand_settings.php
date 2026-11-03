<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A school's public menu (main hierarchy + auxiliary links). Null keeps the
 * generic menu built from the standard pages (see PublicNavigation).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_settings', function (Blueprint $table) {
            $table->json('navigation')->nullable()->after('social_links');
        });
    }

    public function down(): void
    {
        Schema::table('brand_settings', function (Blueprint $table) {
            $table->dropColumn('navigation');
        });
    }
};
