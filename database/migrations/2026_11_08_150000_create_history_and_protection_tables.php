<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable();
        });

        Schema::create('pending_edits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scene_id')->constrained()->cascadeOnDelete();
            $table->string('client_id', 100);
            $table->unsignedBigInteger('base_revision')->default(1);
            $table->json('content');
            $table->string('status', 24)->default('pending');
            $table->timestamps();
            $table->unique(['user_id', 'scene_id', 'client_id']);
        });

        Schema::create('content_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scene_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('revision');
            $table->json('content');
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason', 32)->default('save');
            $table->timestamp('created_at')->nullable();
            $table->unique(['scene_id', 'revision']);
        });

        Schema::create('card_field_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('universe_id')->constrained()->cascadeOnDelete();
            $table->string('key', 100);
            $table->string('type', 32);
            $table->string('label', 200);
            $table->json('options')->nullable();
            $table->boolean('temporal')->default(false);
            $table->timestamp('removed_at')->nullable();
            $table->json('removed_values')->nullable();
            $table->timestamps();
            $table->index(['universe_id', 'key']);
        });

        Schema::create('trash_entries', function (Blueprint $table) {
            $table->id();
            $table->string('resource_type', 16);
            $table->unsignedBigInteger('resource_id');
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('deleted_at');
            $table->timestamp('restore_deadline');
            $table->timestamps();
            $table->unique(['resource_type', 'resource_id']);
            $table->index(['owner_id', 'restore_deadline']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trash_entries');
        Schema::dropIfExists('card_field_definitions');
        Schema::dropIfExists('content_revisions');
        Schema::dropIfExists('pending_edits');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('closed_at');
        });
    }
};
