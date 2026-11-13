<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('admin_accounts')->nullable()->default(null);
            $table->boolean('admin_communications')->nullable()->default(null);
        });

        DB::table('users')->where('is_platform_admin', true)->update([
            'admin_accounts' => true,
            'admin_communications' => true,
        ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['admin_accounts', 'admin_communications']);
        });
    }
};
