<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A comparison could only ever end one way: published to the server. That made two things
 * impossible — reviewing changes without publishing them, and comparing against a translation
 * one does not own (a branch against its Main), since publishing there is forbidden and the
 * comparison was refused upfront.
 *
 * The destination now travels with the token, so each apply route can check that the token
 * belongs to it. Existing tokens keep the only behaviour they ever had.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merge_preview_tokens', function (Blueprint $table) {
            $table->string('destination', 16)->default('server')->after('translation_id');
        });
    }

    public function down(): void
    {
        Schema::table('merge_preview_tokens', function (Blueprint $table) {
            $table->dropColumn('destination');
        });
    }
};
