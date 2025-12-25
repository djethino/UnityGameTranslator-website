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
        // Global daily stats (aggregated, keep forever)
        Schema::create('analytics_daily', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->unsignedInteger('page_views')->default(0);
            $table->unsignedInteger('unique_visitors')->default(0);
            $table->unsignedInteger('downloads')->default(0);
            $table->unsignedInteger('uploads')->default(0);
            $table->unsignedInteger('registrations')->default(0);
            $table->json('countries')->nullable(); // {"FR": 150, "US": 80, ...}
            $table->json('referrers')->nullable(); // {"google.com": 50, "reddit.com": 30, ...}
            $table->json('devices')->nullable(); // {"desktop": 200, "mobile": 100, "tablet": 10}
            $table->json('browsers')->nullable(); // {"Chrome": 150, "Firefox": 80, ...}
            $table->timestamps();
        });

        // Per-game daily stats (keep forever, aggregated)
        Schema::create('analytics_games', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->foreignId('game_id')->constrained()->onDelete('cascade');
            $table->unsignedInteger('page_views')->default(0);
            $table->unsignedInteger('downloads')->default(0);
            $table->timestamps();

            $table->unique(['date', 'game_id']);
        });

        // Individual page view events (purge after 90 days)
        Schema::create('analytics_events', function (Blueprint $table) {
            $table->id();
            $table->string('route', 100); // e.g., "games.show", "home", "docs"
            $table->unsignedBigInteger('game_id')->nullable(); // If viewing a game page
            $table->string('country', 2)->nullable(); // ISO country code
            $table->string('referrer_domain', 100)->nullable(); // e.g., "google.com"
            $table->enum('device', ['desktop', 'mobile', 'tablet'])->default('desktop');
            $table->string('browser', 30)->nullable(); // e.g., "Chrome", "Firefox"
            $table->string('visitor_hash', 32)->nullable(); // Hash for unique visitors (no IP stored)
            $table->timestamp('created_at');

            $table->index('created_at');
            $table->index(['game_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('analytics_events');
        Schema::dropIfExists('analytics_games');
        Schema::dropIfExists('analytics_daily');
    }
};
