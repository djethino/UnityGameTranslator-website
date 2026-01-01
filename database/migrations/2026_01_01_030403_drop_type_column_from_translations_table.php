<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the 'type' column from translations table.
 *
 * The 'type' field is now a computed accessor in the Translation model,
 * derived from HVASM stats (human_count, validated_count, ai_count).
 * Storing it was redundant since it can always be calculated from these stats.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            $table->enum('type', ['ai', 'human', 'ai_corrected'])->default('ai')->after('status');
        });
    }
};
