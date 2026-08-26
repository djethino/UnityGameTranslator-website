<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which accounts asked to be forgotten, so a restore can be told.
 *
 * A backup restored from before a deletion brings the account back whole — its display name, its
 * provider, and its API tokens, which means a mod that starts publishing again under a name its
 * owner asked us to erase. The window is not theoretical: the oldest restore point available to us
 * is about five months old.
 *
 * ### What it holds, and why that is not personal data
 *
 * An account id and a date. Nothing else — no name, no address, no reason. Once the row in `users`
 * is anonymised, the id points at nobody, so this table cannot re-identify anyone; it only says
 * "whatever this id was, it must stay erased".
 *
 * ⚠ **No foreign key, deliberately.** Same reasoning as the lineage columns: a record must outlive
 * what it refers to. A cascade here would delete the very evidence that something was deleted.
 *
 * ### 🔴 It only works under one rule: restore BESIDE, never OVER
 *
 * This table lives in the database it protects. Restore a backup over production and it comes back
 * in its older state — without the entry for the deletion that happened afterwards, which is the
 * only case it exists for. So the procedure is part of the mechanism: load a backup into a working
 * database, keep production's live list, and use it to filter what is reintegrated.
 *
 * ⚠ Residual case, accepted and stated in the privacy policy rather than hidden: if production is
 * lost entirely, this list goes with it and recent deletions are no longer known.
 * See analyse/account-deletion-plan.md and analyse/backups-own-copy.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_deletions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->timestamp('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_deletions');
    }
};
