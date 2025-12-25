<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('translation_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->tinyInteger('value'); // 1 = upvote, -1 = downvote
            $table->timestamps();

            $table->unique(['translation_id', 'user_id']); // One vote per user per translation
        });

        // Add vote_count cache column to translations
        Schema::table('translations', function (Blueprint $table) {
            $table->integer('vote_count')->default(0)->after('download_count');
        });
    }

    public function down(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            $table->dropColumn('vote_count');
        });

        Schema::dropIfExists('votes');
    }
};
