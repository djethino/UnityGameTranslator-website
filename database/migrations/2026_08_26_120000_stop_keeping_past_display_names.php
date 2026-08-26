<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stop keeping past display names.
 *
 * The table was written on every rename and **read by nothing** — no admin screen, no query, in the
 * four months it existed. Its stated purpose, anti-impersonation, was real and not redundant with
 * the account id: it answered "who has borne this name before", a search BY NAME, which is why it
 * carried an index on `old_name`. But a log nobody exploits has no purpose, and a purpose is what a
 * lawful basis is made of. The conforming answer is therefore not a shorter retention, it is not
 * collecting it at all: what is not collected needs no documenting, no exporting, and cannot leak.
 *
 * 🔴 **And it held precisely what the feature exists to hide.** The one-shot prompt that offers the
 * rename says OAuth display names sometimes expose real ones. So the name somebody removed in order
 * to protect themselves was the name we filed away, for ever, and which survived even the deletion
 * of their account. It was also absent from the data export, so nobody could see we had it.
 *
 * ⚠ The rest of the anti-impersonation set is untouched and still works: the 30-day cooldown lives
 * in `users.name_changed_at`, and the ASCII-only charset still blocks homoglyphs.
 *
 * ⚠ `down()` recreates the table **empty**. Losing the rows is the point of this migration, not an
 * accident of it — restoring the shape is all a rollback can honestly offer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('username_history');
    }

    public function down(): void
    {
        Schema::create('username_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('old_name');
            $table->timestamp('changed_at');
            $table->timestamps();

            $table->index(['old_name', 'changed_at']);
        });
    }
};
