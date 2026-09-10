<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Real, currently-imported external links (docs/08-content-migration.md,
 * content-migration/library-catalog.json) include Scribd/Google Drive URLs
 * with percent-encoded Georgian titles well over 255 characters — the
 * original `string('external_url')` truncates and the insert fails
 * outright on Postgres (SQLSTATE 22001). Widened to `text` (no length
 * limit); dropped and re-added rather than `->change()` to avoid pulling
 * in doctrine/dbal as a new dependency for one column. Safe locally: no
 * existing row currently has this column populated (TenantSeeder always
 * passes external_url: null; only ImportLibraryLinks writes real values,
 * and only after this migration exists).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('library_resources', function (Blueprint $table) {
            $table->dropColumn('external_url');
        });

        Schema::table('library_resources', function (Blueprint $table) {
            $table->text('external_url')->nullable()->after('access_scope');
        });
    }

    public function down(): void
    {
        Schema::table('library_resources', function (Blueprint $table) {
            $table->dropColumn('external_url');
        });

        Schema::table('library_resources', function (Blueprint $table) {
            $table->string('external_url')->nullable()->after('access_scope');
        });
    }
};
