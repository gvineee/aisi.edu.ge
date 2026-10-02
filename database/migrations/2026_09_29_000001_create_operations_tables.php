<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operations modules (CLAUDE-PLATFORM-MODULES §11), each behind its own
 * feature entitlement: clubs (capacity + ordered waitlist), transport
 * (planned routes/stops and run status only — no live GPS without a
 * provider), meals (versioned published menus, allergen tags, dietary
 * profile with allergen codes only, opt-outs).
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- Clubs ---
        Schema::create('clubs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('leader_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('capacity');
            // Integer minor units (tetri) + currency; billed via Finance later.
            $table->unsignedInteger('fee_minor')->nullable();
            $table->char('currency', 3)->default('GEL');
            $table->boolean('is_open')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'is_open']);
        });

        Schema::create('club_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week'); // 1 = Monday … 7 = Sunday
            $table->time('starts_at');
            $table->time('ends_at');
            $table->string('location')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'club_id']);
        });

        Schema::create('club_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('enrolled'); // enrolled | cancelled
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['club_id', 'student_id']);
            $table->index(['tenant_id', 'student_id']);
        });

        Schema::create('club_waitlist_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('waiting'); // waiting | promoted | cancelled
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            // Order = id (first come, first served).
            $table->unique(['club_id', 'student_id']);
            $table->index(['tenant_id', 'club_id', 'status'], 'club_waitlist_club_status_idx');
        });

        // --- Transport ---
        Schema::create('transport_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('direction', 16); // morning | afternoon
            $table->string('vehicle_label')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('transport_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transport_route_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->time('planned_time');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'transport_route_id']);
        });

        Schema::create('student_transport_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transport_route_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transport_stop_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['student_id', 'transport_route_id'], 'student_transport_student_route_uq');
            $table->index(['tenant_id', 'transport_route_id'], 'student_transport_tenant_route_idx');
        });

        Schema::create('transport_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transport_route_id')->constrained()->cascadeOnDelete();
            $table->date('run_date');
            // planned | departed | delayed | completed | cancelled
            $table->string('status')->default('planned');
            $table->string('note')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['transport_route_id', 'run_date']);
        });

        Schema::create('vehicle_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transport_run_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->string('note')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'transport_run_id']);
        });

        // --- Meals ---
        Schema::create('meal_menus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('served_on');
            $table->unsignedInteger('version');
            $table->string('status')->default('draft'); // draft | published | superseded
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'served_on', 'version']);
        });

        Schema::create('meal_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meal_menu_id')->constrained()->cascadeOnDelete();
            $table->string('course', 32);
            $table->string('name');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'meal_menu_id']);
        });

        Schema::create('meal_allergens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meal_item_id')->constrained()->cascadeOnDelete();
            $table->string('allergen', 32);

            $table->unique(['meal_item_id', 'allergen']);
        });

        Schema::create('student_dietary_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->unique()->constrained()->cascadeOnDelete();
            // Allergen codes only — the kitchen's minimal projection, never
            // the health record's diagnosis or notes.
            $table->json('allergens');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('meal_selections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->date('served_on');
            $table->string('choice', 16); // opted_out
            $table->foreignId('selected_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['student_id', 'served_on']);
            $table->index(['tenant_id', 'served_on']);
        });
    }

    public function down(): void
    {
        foreach ([
            'meal_selections', 'student_dietary_profiles', 'meal_allergens', 'meal_items', 'meal_menus',
            'vehicle_events', 'transport_runs', 'student_transport_assignments', 'transport_stops', 'transport_routes',
            'club_waitlist_entries', 'club_enrollments', 'club_sessions', 'clubs',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
