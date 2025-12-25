<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            $table->enum('type', ['ai', 'human', 'ai_corrected'])->default('ai')->after('status');
            $table->text('notes')->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            $table->dropColumn(['type', 'notes']);
        });
    }
};
