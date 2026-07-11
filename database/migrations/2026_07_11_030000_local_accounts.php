<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Local (platform-less) accounts: unique login identifier.
            // OAuth accounts keep it null — their identity is provider+provider_id.
            $table->string('username', 32)->nullable()->unique()->after('name');
        });

        // Anonymity-first accounts have no email at all
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });

        // One-time recovery codes (hashed like passwords)
        Schema::create('recovery_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code_hash');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recovery_codes');
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }
};
