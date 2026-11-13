<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['universes', 'novels', 'chapters', 'chapter_alternatives', 'scenes', 'cards'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->unsignedBigInteger('revision')->default(1);
            });
        }

        Schema::create('edit_leases', function (Blueprint $table) {
            $table->id();
            $table->string('resource_type', 32);
            $table->unsignedBigInteger('resource_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->unique(['resource_type', 'resource_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edit_leases');

        foreach (['universes', 'novels', 'chapters', 'chapter_alternatives', 'scenes', 'cards'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('revision');
            });
        }
    }
};
