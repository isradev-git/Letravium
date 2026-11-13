<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('novel_id')->constrained()->cascadeOnDelete();
            $table->string('name', 200);
            $table->json('manifest');
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index(['novel_id', 'created_at']);
        });

        Schema::create('snapshot_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapshot_id')->constrained()->cascadeOnDelete();
            $table->string('resource_type', 40);
            $table->unsignedBigInteger('resource_id');
            $table->unsignedBigInteger('revision')->default(0);
            $table->json('payload');
            $table->index(['snapshot_id', 'resource_type', 'resource_id']);
        });

        Schema::create('history_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('novel_id')->constrained()->cascadeOnDelete();
            $table->string('resource_type', 40);
            $table->unsignedBigInteger('resource_id');
            $table->unsignedBigInteger('revision')->default(0);
            $table->json('payload');
            $table->string('reason', 40);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index(['novel_id', 'resource_type', 'resource_id']);
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('novel_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('chapter_id')->nullable()->index();
            $table->unsignedBigInteger('scene_id')->nullable()->index();
            $table->foreignId('snapshot_id')->nullable()->constrained()->nullOnDelete();
            $table->string('block_id', 120)->nullable();
            $table->text('quoted_text')->nullable();
            $table->string('status', 20)->default('open');
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->index(['novel_id', 'status']);
        });

        Schema::create('comment_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('correction_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comment_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('base_revision')->default(0);
            $table->text('original');
            $table->text('proposed');
            $table->string('status', 20)->default('pending');
            $table->timestamps();
        });

        Schema::create('beta_readers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapshot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email', 200)->nullable();
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at');
            $table->boolean('can_comment')->default(true);
            $table->json('scope')->nullable();
            $table->timestamps();
            $table->index(['snapshot_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beta_readers');
        Schema::dropIfExists('correction_proposals');
        Schema::dropIfExists('comment_messages');
        Schema::dropIfExists('comments');
        Schema::dropIfExists('history_entries');
        Schema::dropIfExists('snapshot_items');
        Schema::dropIfExists('snapshots');
    }
};
