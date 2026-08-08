<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two things the lineage could not say.
 *
 * WHERE A FORK COMES FROM, AND HOW MUCH IT WAS GIVEN.
 *
 * Converting a branch to a fork gives it a new file_uuid, so it leaves its lineage by design and
 * the only remaining link is parent_id — declared "on delete set null". Delete one ancestor and
 * everything below loses its ancestry. Nothing displayed the parent either, so forking silently
 * erased the credit owed to whoever started the work.
 *
 * origin_translation_id and origin_user_id carry NO foreign key on purpose. That is the whole
 * point: an inscription has to survive the disappearance of what it refers to. parent_id stays
 * as the live link while it holds; these say who started this even when the row is gone.
 *
 * origin_resolved_lines is a SNAPSHOT, and it can only be taken at the moment of the fork: the
 * original keeps growing afterwards, so measuring later would answer a different question. It
 * turns a vague "based on X's work" into "3,000 lines came from X" — and it stays honest as the
 * fork grows, since it never moves. origin_file_hash says which exact version was taken.
 *
 * WHETHER A BRANCH WAS EVER TAKEN.
 *
 * Nothing recorded a merge, so "the Main publishes and never merges you" — the most
 * discouraging thing that can happen to a contributor — could not be told apart from "the Main
 * merged you and moved on". Comparing dates would have accused attentive maintainers.
 *
 * Nothing to backfill: every existing child in the catalogue is a branch, not a fork.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            $table->unsignedBigInteger('origin_translation_id')->nullable()->after('parent_id');
            $table->unsignedBigInteger('origin_user_id')->nullable()->after('origin_translation_id');
            $table->unsignedInteger('origin_resolved_lines')->nullable()->after('origin_user_id');
            $table->string('origin_file_hash')->nullable()->after('origin_resolved_lines');
            $table->timestamp('merged_at')->nullable()->after('content_updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            $table->dropColumn([
                'origin_translation_id',
                'origin_user_id',
                'origin_resolved_lines',
                'origin_file_hash',
                'merged_at',
            ]);
        });
    }
};
