<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How much of a branch's work has been taken in, cumulatively.
 *
 * merged_at says WHEN a contribution was last picked up; this says HOW MUCH, added up over every
 * merge. The count existed only inside the "your work was merged: N lines" notification and was
 * thrown away with it, so a contributor whose three hundred lines were taken in over six merges
 * had no trace of their apport anywhere.
 *
 * Deliberately a running total, not an inventory: a line later replaced by another branch's
 * version, or rewritten by the Main, still counts. What is being measured is a contribution over
 * time, not what remains of it in today's file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            $table->unsignedInteger('merged_lines_total')->default(0)->after('merged_at');
        });
    }

    public function down(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            $table->dropColumn('merged_lines_total');
        });
    }
};
