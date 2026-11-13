<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('styles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('novel_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('kind', 16);
            $table->json('definition');
            $table->timestamps();
            $table->unique(['novel_id', 'kind', 'name']);
        });

        Schema::create('footnotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('novel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scene_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 8);
            $table->unsignedInteger('number');
            $table->text('text');
            $table->timestamps();
            $table->unique(['novel_id', 'kind', 'number']);
        });

        Schema::create('media_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('universe_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('novel_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 16);
            $table->string('original_name');
            $table->string('mime', 100);
            $table->unsignedBigInteger('bytes');
            $table->string('path');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['owner_id', 'kind']);
        });

        Schema::create('fonts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('family', 160);
            $table->string('variant', 80);
            $table->string('path');
            $table->unsignedBigInteger('bytes');
            $table->string('license', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['owner_id', 'family']);
        });

        Schema::create('image_maps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('universe_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('novel_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('image_asset_id')->constrained('media_assets')->restrictOnDelete();
            $table->json('markers')->nullable();
            $table->timestamps();
            $table->index('universe_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('image_maps');
        Schema::dropIfExists('fonts');
        Schema::dropIfExists('media_assets');
        Schema::dropIfExists('footnotes');
        Schema::dropIfExists('styles');
    }
};
