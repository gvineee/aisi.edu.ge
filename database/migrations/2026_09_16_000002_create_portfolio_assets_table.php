<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('portfolio_item_id')->constrained()->cascadeOnDelete();
            $table->string('storage_path');
            $table->string('checksum', 64);
            $table->string('mime');
            $table->unsignedBigInteger('size');
            $table->string('original_filename');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_assets');
    }
};
