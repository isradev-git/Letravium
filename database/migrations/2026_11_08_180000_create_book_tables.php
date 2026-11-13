<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('book_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('novel_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('page_size', 60)->default('148x210mm');
            $table->string('orientation', 16)->default('portrait');
            $table->json('margins')->nullable();
            $table->string('binding', 80)->nullable();
            $table->json('fonts')->nullable();
            $table->json('chapter_policy')->nullable();
            $table->json('notes_policy')->nullable();
            $table->boolean('facing_pages')->default(true);
            $table->boolean('right_page_starts')->default(false);
            $table->json('running_heads')->nullable();
            $table->unsignedTinyInteger('widows')->default(2);
            $table->unsignedTinyInteger('orphans')->default(2);
            $table->boolean('keep_with_next')->default(true);
            $table->json('cover')->nullable();
            $table->json('compositor_limits')->nullable();
            $table->timestamps();
        });

        Schema::create('book_metadata', function (Blueprint $table) {
            $table->id();
            $table->foreignId('novel_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->string('subtitle')->nullable();
            $table->string('author')->nullable();
            $table->string('language', 16)->default('es');
            $table->text('description')->nullable();
            $table->string('rights', 500)->nullable();
            $table->string('edition', 120)->nullable();
            $table->string('publisher', 200)->nullable();
            $table->string('isbn', 32)->nullable();
            $table->json('extra')->nullable();
            $table->timestamps();
        });

        Schema::create('book_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('novel_id')->constrained()->cascadeOnDelete();
            $table->string('area', 8);
            $table->string('type', 40);
            $table->string('title')->nullable();
            $table->text('content')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('include_in_export')->default(true);
            $table->timestamps();
            $table->index(['novel_id', 'area', 'position']);
        });

        Schema::create('book_marks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('novel_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);
            $table->string('label')->nullable();
            $table->text('caption')->nullable();
            $table->string('target_type', 32)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('sort_key')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->json('data')->nullable();
            $table->timestamps();
            $table->index(['novel_id', 'type', 'position']);
        });

        Schema::create('export_manifests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('novel_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('snapshot_id')->nullable();
            $table->json('formats');
            $table->json('manifest');
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_manifests');
        Schema::dropIfExists('book_marks');
        Schema::dropIfExists('book_sections');
        Schema::dropIfExists('book_metadata');
        Schema::dropIfExists('book_profiles');
    }
};
