<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('parent_id')->nullable()->constrained('translations')->onDelete('set null');
            $table->string('source_language', 50);
            $table->string('target_language', 50);
            $table->integer('line_count')->default(0);
            $table->enum('status', ['in_progress', 'complete'])->default('in_progress');
            $table->string('file_path');
            $table->integer('download_count')->default(0);
            $table->timestamps();

            $table->index(['game_id', 'source_language', 'target_language']);
            $table->index('target_language');
            $table->index('line_count');
            $table->index('download_count');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translations');
    }
};
