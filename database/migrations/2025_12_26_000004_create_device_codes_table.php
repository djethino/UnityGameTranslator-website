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
        Schema::create('device_codes', function (Blueprint $table) {
            $table->id();
            $table->string('device_code', 64)->unique();
            $table->string('user_code', 8)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index('device_code');
            $table->index('user_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_codes');
    }
};
