<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rename the accounts erased before deletions started carrying their own name.
 *
 * Every deletion used to write the same literal `[Deleted]`. Three of them collided in production,
 * which is what surfaced all of this: erased accounts were indistinguishable from one another, and
 * no unique index on `name` could ever be added while they were.
 *
 * The previous migration gave them `account_deleted_at` so the question "is this account gone?"
 * has a real answer. It deliberately did not rename them: marking a state and rewriting rows are
 * two different acts, and the second is the one worth being able to run on its own.
 *
 * ⚠ **Through the model rather than a literal format written here.** `User::deletedAccountName`
 * is where the shape of this name is decided, and it already loops until the name is free. A
 * migration carrying its own copy of the format would be a second answer to one question.
 *
 * ⚠ **DB::table, not Eloquent**: `updated_at` must not move. These rows record when an account was
 * erased, and a maintenance pass has no business claiming they changed today.
 *
 * ⚠ Irreversible by nature — down() cannot put back names that were identical on purpose, and
 * restoring the collision would be undoing the fix rather than the change.
 */
return new class extends Migration
{
    public function up(): void
    {
        $stale = DB::table('users')->where('name', '[Deleted]')->pluck('id');

        foreach ($stale as $id) {
            DB::table('users')
                ->where('id', $id)
                ->update(['name' => User::deletedAccountName()]);
        }
    }

    public function down(): void
    {
        // Nothing: the previous state was several rows sharing one name, which is the defect.
    }
};
