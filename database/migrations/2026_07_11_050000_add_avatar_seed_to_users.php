<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Locally-generated avatar (DiceBear) seed. When set, it takes
            // precedence over the OAuth avatar URL — no image is ever
            // uploaded or hosted, the SVG is generated client-side.
            $table->string('avatar_seed', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('avatar_seed');
        });
    }
};
