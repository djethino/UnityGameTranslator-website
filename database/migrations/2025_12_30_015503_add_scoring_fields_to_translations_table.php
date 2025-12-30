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
        Schema::table('translations', function (Blueprint $table) {
            // Count of H entries with empty value (capture only, excluded from scoring)
            $table->unsignedInteger('capture_count')->default(0)->after('line_count');

            // Rating given by Main owner to this branch (1-5 stars)
            $table->unsignedTinyInteger('main_rating')->nullable()->after('capture_count');

            // Hash of file when Main rated it (to detect if branch changed since review)
            $table->string('reviewed_hash', 64)->nullable()->after('main_rating');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            $table->dropColumn(['capture_count', 'main_rating', 'reviewed_hash']);
        });
    }
};
