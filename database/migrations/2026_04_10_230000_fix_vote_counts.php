<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Delete orphan votes with NULL user_id (caused by API auth bug)
        DB::table('votes')->whereNull('user_id')->delete();

        // Recalculate vote_count from actual votes for all translations
        DB::statement('
            UPDATE translations SET vote_count = (
                SELECT COALESCE(SUM(value), 0)
                FROM votes
                WHERE votes.translation_id = translations.id
            )
        ');
    }

    public function down(): void
    {
        // Cannot restore deleted orphan votes
    }
};
