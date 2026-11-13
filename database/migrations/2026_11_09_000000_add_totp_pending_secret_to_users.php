<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('totp_pending_secret')->nullable()->after('totp_secret');
            $table->timestamp('totp_pending_started_at')->nullable()->after('totp_pending_secret');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['totp_pending_secret', 'totp_pending_started_at']);
        });
    }
};
