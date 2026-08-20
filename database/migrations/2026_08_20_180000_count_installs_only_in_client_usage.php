<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the request counter: what is wanted is how many copies, not how chatty they are.
 *
 * ⚠ **The column was the reason for writing on every single API call.** Counting requests meant an
 * upsert per call, polling included, on a shared host — a real price for a number that only ever
 * reflected how often a build happens to poll. Counting copies needs one write per installation per
 * day; every later call is now an indexed read and nothing else.
 *
 * ⚠ Safe to drop: the table was created hours earlier the same day and holds nothing but test
 * calls. Were it not, this would still only lose a figure nobody was reading.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_usage_daily', function (Blueprint $table) {
            $table->dropColumn('requests');
        });
    }

    public function down(): void
    {
        Schema::table('client_usage_daily', function (Blueprint $table) {
            $table->unsignedBigInteger('requests')->default(0);
        });
    }
};
