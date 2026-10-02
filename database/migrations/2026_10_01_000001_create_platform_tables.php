<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * White-label platform administration (CLAUDE-PLATFORM-MODULES §14).
 * Platform operators are a separate model from tenant roles; support
 * access is requested, approved by the tenant, time-limited and audited.
 * Subscription plans are configuration only — no invented prices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_operators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('platform_audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 80);
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            // Optional until real commercial terms exist — never invented.
            $table->unsignedBigInteger('price_minor')->nullable();
            $table->char('currency', 3)->default('GEL');
            $table->string('billing_period', 16)->nullable(); // monthly | yearly
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('plan_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_plan_id')->constrained()->cascadeOnDelete();
            $table->string('feature', 40);

            $table->unique(['subscription_plan_id', 'feature']);
        });

        Schema::create('tenant_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_plan_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16); // trial | active | suspended | cancelled
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('tenant_onboarding_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('step', 32);
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'step']);
        });

        Schema::create('support_access_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('operator_id')->constrained('users')->cascadeOnDelete();
            $table->text('reason');
            $table->unsignedSmallInteger('requested_minutes');
            // requested | approved | denied | revoked
            $table->string('status', 16)->default('requested');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::table('tenant_domains', function (Blueprint $table) {
            // A domain added through the platform starts pending: it has a
            // token and serves nothing until the DNS proof is verified.
            $table->string('verification_token', 64)->nullable()->after('is_primary');
            $table->timestamp('verified_at')->nullable()->after('verification_token');
        });

        // Existing domains were configured by hand on the server and are
        // already live — record them as verified.
        DB::table('tenant_domains')->whereNull('verified_at')->update(['verified_at' => now()]);

        Schema::table('brand_settings', function (Blueprint $table) {
            $table->string('logo_dark_path')->nullable()->after('logo_path');
            $table->string('icon_path')->nullable()->after('logo_dark_path');
        });
    }

    public function down(): void
    {
        Schema::table('brand_settings', function (Blueprint $table) {
            $table->dropColumn(['logo_dark_path', 'icon_path']);
        });
        Schema::table('tenant_domains', function (Blueprint $table) {
            $table->dropColumn(['verification_token', 'verified_at']);
        });

        foreach (['support_access_requests', 'tenant_onboarding_steps', 'tenant_subscriptions', 'plan_features', 'subscription_plans', 'platform_audit_events', 'platform_operators'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
