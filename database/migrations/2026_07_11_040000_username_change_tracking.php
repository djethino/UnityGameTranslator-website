<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Display-name change cooldown + one-shot migration overlay
            $table->timestamp('name_changed_at')->nullable();
            $table->timestamp('username_prompt_seen_at')->nullable();
        });

        // Admin-only history (anti-impersonation + moderation). Never public.
        Schema::create('username_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('old_name');
            $table->timestamp('changed_at');
            $table->timestamps();

            $table->index(['old_name', 'changed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('username_history');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['name_changed_at', 'username_prompt_seen_at']);
        });
    }
};
