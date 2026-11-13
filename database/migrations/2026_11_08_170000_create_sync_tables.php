<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->timestamp('authorized_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'revoked_at']);
        });

        Schema::create('device_projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('novel_id')->constrained()->cascadeOnDelete();
            $table->json('manifest')->nullable();
            $table->timestamps();
            $table->unique(['device_id', 'novel_id']);
        });

        Schema::create('sync_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key', 191)->unique();
            $table->char('payload_hash', 64);
            $table->json('result')->nullable();
            $table->timestamps();
        });

        Schema::create('sync_conflicts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scene_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('resource_type', 32)->default('scene');
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->unsignedBigInteger('server_revision');
            $table->unsignedBigInteger('client_revision');
            $table->json('server_content')->nullable();
            $table->json('client_content')->nullable();
            $table->string('status', 32)->default('pending');
            $table->timestamps();
            $table->index(['status', 'resource_type', 'resource_id']);
        });

        Schema::create('sync_trash', function (Blueprint $table) {
            $table->id();
            $table->string('resource_type', 32);
            $table->unsignedBigInteger('resource_id');
            $table->timestamp('trashed_at');
            $table->timestamps();
            $table->unique(['resource_type', 'resource_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_trash');
        Schema::dropIfExists('sync_conflicts');
        Schema::dropIfExists('sync_operations');
        Schema::dropIfExists('device_projects');
        Schema::dropIfExists('devices');
    }
};
