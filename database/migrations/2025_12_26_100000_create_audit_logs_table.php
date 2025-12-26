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
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 50); // login, logout, token_created, translation_upload, etc.
            $table->string('entity_type', 50)->nullable(); // Translation, User, Game, etc.
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('metadata')->nullable(); // Additional context (IP, user agent, changes, etc.)
            $table->string('ip_address', 45)->nullable(); // IPv6 compatible
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Indexes for common queries
            $table->index('action');
            $table->index('entity_type');
            $table->index('created_at');
            $table->index(['entity_type', 'entity_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
