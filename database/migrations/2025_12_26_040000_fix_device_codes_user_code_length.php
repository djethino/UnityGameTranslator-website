<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fix user_code column length.
     * Format ABCD-1234 is 9 characters, not 8.
     */
    public function up(): void
    {
        Schema::table('device_codes', function (Blueprint $table) {
            $table->string('user_code', 9)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('device_codes', function (Blueprint $table) {
            $table->string('user_code', 8)->change();
        });
    }
};
