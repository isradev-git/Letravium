<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('totp_secret')->nullable();
            $table->timestamp('totp_confirmed_at')->nullable();
            $table->unsignedBigInteger('last_totp_timestep')->nullable();
            $table->json('recovery_codes')->nullable();
            $table->boolean('is_platform_admin')->default(false);
            $table->timestamp('marketing_consent_at')->nullable();
        });

        Schema::create('creative_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->string('email')->index();
            $table->string('token', 64)->unique();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 64);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_events');
        Schema::dropIfExists('invitations');
        Schema::dropIfExists('creative_memberships');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'totp_secret', 'totp_confirmed_at', 'last_totp_timestep',
                'recovery_codes', 'is_platform_admin', 'marketing_consent_at',
            ]);
        });
    }
};
