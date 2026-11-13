<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('suspended_at')->nullable();
        });

        Schema::create('account_quotas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('bytes_limit')->default(262144000);
            $table->unsignedBigInteger('bytes_used')->default(0);
            $table->timestamps();
        });

        Schema::create('admin_audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $table->string('action', 64);
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('universes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->index(['owner_id', 'id']);
        });

        Schema::create('novels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('universe_id')->constrained('universes')->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->string('status', 32)->default('active');
            $table->timestamp('archived_at')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->index(['owner_id', 'archived_at']);
        });

        Schema::create('collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('collection_novel', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_id')->constrained('collections')->cascadeOnDelete();
            $table->foreignId('novel_id')->constrained('novels')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->unique(['collection_id', 'novel_id']);
        });

        Schema::create('parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('novel_id')->constrained('novels')->cascadeOnDelete();
            $table->string('title');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->index(['novel_id', 'position']);
        });

        Schema::create('work_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('novel_id')->constrained('novels')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('chapters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('novel_id')->constrained('novels')->cascadeOnDelete();
            $table->foreignId('part_id')->nullable()->constrained('parts')->nullOnDelete();
            $table->string('title')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->json('plan')->nullable();
            $table->timestamps();
            $table->index(['novel_id', 'position']);
        });

        Schema::create('chapter_alternatives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chapter_id')->constrained('chapters')->cascadeOnDelete();
            $table->foreignId('work_state_id')->nullable()->constrained('work_states')->nullOnDelete();
            $table->string('alias');
            $table->boolean('is_active')->default(false);
            $table->json('plan')->nullable();
            $table->timestamps();
            $table->index(['chapter_id', 'is_active']);
        });

        Schema::create('scenes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chapter_alternative_id')->constrained('chapter_alternatives')->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->text('summary')->nullable();
            $table->unsignedBigInteger('pov_card_id')->nullable();
            $table->unsignedBigInteger('place_card_id')->nullable();
            $table->string('narrative_moment')->nullable();
            $table->json('plan')->nullable();
            $table->json('content')->nullable();
            $table->timestamps();
            $table->index(['chapter_alternative_id', 'position']);
        });

        Schema::create('plots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('novel_id')->constrained('novels')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('plot_scene', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plot_id')->constrained('plots')->cascadeOnDelete();
            $table->foreignId('scene_id')->constrained('scenes')->cascadeOnDelete();
            $table->unique(['plot_id', 'scene_id']);
        });

        Schema::create('novel_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('novel_id')->unique()->constrained('novels')->cascadeOnDelete();
            $table->unsignedInteger('target_words')->nullable();
            $table->timestamps();
        });

        Schema::create('cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('universe_id')->constrained('universes')->cascadeOnDelete();
            $table->string('area', 32);
            $table->string('name');
            $table->string('template', 64)->nullable();
            $table->json('fields')->nullable();
            $table->json('text')->nullable();
            $table->timestamps();
            $table->index(['universe_id', 'area']);
        });

        Schema::create('card_relationships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('universe_id')->constrained('universes')->cascadeOnDelete();
            $table->foreignId('source_card_id')->constrained('cards')->cascadeOnDelete();
            $table->foreignId('target_card_id')->constrained('cards')->cascadeOnDelete();
            $table->string('label');
            $table->boolean('directed')->default(true);
            $table->timestamps();
        });

        Schema::create('text_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scene_id')->constrained('scenes')->cascadeOnDelete();
            $table->foreignId('card_id')->constrained('cards')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['scene_id', 'card_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('text_references');
        Schema::dropIfExists('card_relationships');
        Schema::dropIfExists('cards');
        Schema::dropIfExists('novel_goals');
        Schema::dropIfExists('plot_scene');
        Schema::dropIfExists('plots');
        Schema::dropIfExists('scenes');
        Schema::dropIfExists('chapter_alternatives');
        Schema::dropIfExists('chapters');
        Schema::dropIfExists('work_states');
        Schema::dropIfExists('parts');
        Schema::dropIfExists('collection_novel');
        Schema::dropIfExists('collections');
        Schema::dropIfExists('novels');
        Schema::dropIfExists('universes');
        Schema::dropIfExists('admin_audit_events');
        Schema::dropIfExists('account_quotas');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('suspended_at');
        });
    }
};
