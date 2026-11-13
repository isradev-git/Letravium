<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_operations', function (Blueprint $table) {
            $table->dropUnique('sync_operations_idempotency_key_unique');
            $table->unique(['user_id', 'device_id', 'idempotency_key'], 'sync_operations_user_device_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('sync_operations', function (Blueprint $table) {
            $table->dropUnique('sync_operations_user_device_key_unique');
            $table->unique('idempotency_key');
        });
    }
};
