<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds support for Main/Branch/Fork system:
     * - visibility: 'public' for Main/Fork, 'branch' for private branches
     * - human_count, validated_count, ai_count: HCA tag statistics
     */
    public function up(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            // Visibility: public (Main/Fork) or branch (private contributor)
            $table->enum('visibility', ['public', 'branch'])->default('public')->after('status');

            // HCA tag counters for quality scoring
            $table->integer('human_count')->default(0)->after('line_count');
            $table->integer('validated_count')->default(0)->after('human_count');
            $table->integer('ai_count')->default(0)->after('validated_count');

            // Index for efficient branch queries
            $table->index(['file_uuid', 'visibility']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            $table->dropIndex(['file_uuid', 'visibility']);
            $table->dropColumn(['visibility', 'human_count', 'validated_count', 'ai_count']);
        });
    }
};
