<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('novel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('card_id')->constrained('cards')->cascadeOnDelete();
            $table->string('role')->nullable();
            $table->text('goals')->nullable();
            $table->text('arc')->nullable();
            $table->timestamps();
            $table->unique(['novel_id', 'card_id']);
        });

        Schema::create('place_containments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('universe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_card_id')->constrained('cards')->cascadeOnDelete();
            $table->foreignId('child_card_id')->constrained('cards')->cascadeOnDelete();
            $table->timestamps();
            $table->unique('child_card_id');
        });

        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('universe_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 16)->default('event');
            $table->text('description')->nullable();
            $table->string('moment')->nullable();
            $table->json('participants')->nullable();
            $table->json('places')->nullable();
            $table->timestamps();
            $table->index(['universe_id', 'type']);
        });

        Schema::create('event_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('universe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('to_event_id')->constrained('events')->cascadeOnDelete();
            $table->string('relation', 16);
            $table->timestamps();
            $table->index(['universe_id', 'from_event_id', 'to_event_id']);
        });

        Schema::create('calendars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('universe_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('config')->nullable();
            $table->boolean('is_current')->default(true);
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();
            $table->index(['universe_id', 'is_current']);
        });

        Schema::create('calendar_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('universe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_calendar_id')->constrained('calendars')->cascadeOnDelete();
            $table->foreignId('to_calendar_id')->constrained('calendars')->cascadeOnDelete();
            $table->string('from_label');
            $table->string('to_label');
            $table->timestamps();
            $table->unique(['from_calendar_id', 'to_calendar_id', 'from_label'], 'calendar_conversions_unique');
        });

        Schema::create('story_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('universe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('card_id')->constrained('cards')->cascadeOnDelete();
            $table->string('field', 64);
            $table->json('value')->nullable();
            $table->foreignId('effective_event_id')->nullable()->constrained('events')->nullOnDelete();
            $table->string('moment')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['card_id', 'field']);
        });

        Schema::create('story_contexts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('universe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('novel_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('scene_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('card_id')->nullable()->constrained('cards')->nullOnDelete();
            $table->foreignId('event_id')->nullable()->constrained('events')->nullOnDelete();
            $table->json('context');
            $table->timestamp('captured_at');
            $table->timestamps();
            $table->index(['scene_id', 'card_id']);
        });

        Schema::create('object_custodies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('universe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('card_id')->constrained('cards')->cascadeOnDelete();
            $table->foreignId('holder_card_id')->nullable()->constrained('cards')->nullOnDelete();
            $table->foreignId('event_id')->nullable()->constrained('events')->nullOnDelete();
            $table->string('moment')->nullable();
            $table->timestamps();
            $table->index(['card_id', 'event_id']);
        });

        Schema::create('resource_origins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('universe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('card_id')->constrained('cards')->cascadeOnDelete();
            $table->string('origin')->nullable();
            $table->foreignId('place_card_id')->nullable()->constrained('cards')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('glossary_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('universe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('language_card_id')->nullable()->constrained('cards')->nullOnDelete();
            $table->string('term');
            $table->text('meaning')->nullable();
            $table->string('preferred_form')->nullable();
            $table->json('variants')->nullable();
            $table->json('examples')->nullable();
            $table->timestamps();
            $table->index(['universe_id', 'term']);
        });

        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('universe_id')->nullable();
            $table->unsignedBigInteger('novel_id')->nullable();
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->text('body')->nullable();
            $table->json('content')->nullable();
            $table->boolean('private')->default(true);
            $table->boolean('pending')->default(false);
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['author_id', 'pending']);
            $table->index('universe_id');
            $table->index('novel_id');
        });

        Schema::create('note_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('note_id')->constrained()->cascadeOnDelete();
            $table->string('linkable_type', 32);
            $table->unsignedBigInteger('linkable_id');
            $table->text('anchor')->nullable();
            $table->timestamps();
            $table->index(['linkable_type', 'linkable_id']);
        });

        Schema::create('bibliography_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('universe_id')->nullable();
            $table->unsignedBigInteger('novel_id')->nullable();
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->string('author')->nullable();
            $table->string('title');
            $table->string('date')->nullable();
            $table->string('publisher')->nullable();
            $table->text('url')->nullable();
            $table->text('observations')->nullable();
            $table->boolean('selected')->default(false);
            $table->timestamps();
            $table->index(['novel_id', 'selected']);
        });

        Schema::create('bibliography_entry_note', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bibliography_entry_id')->constrained('bibliography_entries')->cascadeOnDelete();
            $table->foreignId('note_id')->constrained()->cascadeOnDelete();
            $table->unique(['bibliography_entry_id', 'note_id']);
        });

        Schema::create('continuity_dismissals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('universe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('fingerprint', 64);
            $table->timestamp('dismissed_at');
            $table->timestamps();
            $table->unique(['universe_id', 'user_id', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('continuity_dismissals');
        Schema::dropIfExists('bibliography_entry_note');
        Schema::dropIfExists('bibliography_entries');
        Schema::dropIfExists('note_links');
        Schema::dropIfExists('notes');
        Schema::dropIfExists('glossary_terms');
        Schema::dropIfExists('resource_origins');
        Schema::dropIfExists('object_custodies');
        Schema::dropIfExists('story_contexts');
        Schema::dropIfExists('story_changes');
        Schema::dropIfExists('calendar_conversions');
        Schema::dropIfExists('calendars');
        Schema::dropIfExists('event_relations');
        Schema::dropIfExists('events');
        Schema::dropIfExists('place_containments');
        Schema::dropIfExists('character_roles');
    }
};
