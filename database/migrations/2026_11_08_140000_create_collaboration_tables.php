<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creative_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('resource_type', 16);
            $table->unsignedBigInteger('resource_id');
            $table->string('scope', 24);
            $table->foreignId('granted_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'resource_type', 'resource_id', 'scope'], 'creative_permissions_scope_unique');
            $table->index(['resource_type', 'resource_id']);
        });

        Schema::create('accepted_words', function (Blueprint $table) {
            $table->id();
            $table->foreignId('universe_id')->constrained()->cascadeOnDelete();
            $table->string('word', 200);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['universe_id', 'word']);
        });

        Schema::create('vocabulary_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('universe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('novel_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('granted_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['universe_id', 'novel_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vocabulary_grants');
        Schema::dropIfExists('accepted_words');
        Schema::dropIfExists('creative_permissions');
    }
};
