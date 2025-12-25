<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->unsignedBigInteger('igdb_id')->nullable()->unique()->after('slug');
            $table->unsignedBigInteger('rawg_id')->nullable()->unique()->after('igdb_id');
            $table->string('image_url')->nullable()->after('rawg_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn(['igdb_id', 'rawg_id', 'image_url']);
        });
    }
};
