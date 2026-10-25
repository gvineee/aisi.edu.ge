<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Site navigation taxonomy for CMS content (CLAUDE-CONTENT-CATEGORIES.md):
 * tenant-scoped categories and a pivot placing a page or post into one
 * with a section and order. Placing never changes the content itself —
 * text, dates and status stay on the page/post; the original WordPress
 * category/tag ids stay with the import record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('content_categories')->nullOnDelete();
            $table->string('slug', 64);
            $table->string('locale', 5)->default('ka');
            $table->string('name');
            // Public page that lists this category's items (null = not public, e.g. archive).
            $table->string('hub_path', 191)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_public')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'slug', 'locale'], 'content_categories_slug_unique');
        });

        Schema::create('content_categorizables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('content_category_id')->constrained()->cascadeOnDelete();
            $table->string('categorizable_type', 191);
            $table->unsignedBigInteger('categorizable_id');
            $table->string('section', 64)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            // Where the placement came from: plan source key, or a person in the CMS.
            $table->string('placed_by_source', 191)->nullable();
            $table->timestamps();
            $table->unique(['categorizable_type', 'categorizable_id'], 'content_categorizables_item_unique');
            $table->index(['tenant_id', 'content_category_id', 'position'], 'content_categorizables_listing_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_categorizables');
        Schema::dropIfExists('content_categories');
    }
};
