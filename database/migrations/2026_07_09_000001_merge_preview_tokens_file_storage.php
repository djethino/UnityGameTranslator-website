<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Move merge-preview local content out of the database.
     *
     * The content now lives as a JSON file on the private disk
     * (merge-previews/{token}.json), like translation files. Large files
     * were silently truncated/lost by shared-hosting MySQL limits when
     * stored in the local_content column or in the session payload.
     *
     * consumed_at replaces the delete-on-use behavior: the row must survive
     * consumption so the post-redirect request can locate the content file
     * and so applyMergePreview can publish the SSE merge_completed event.
     */
    public function up(): void
    {
        Schema::table('merge_preview_tokens', function (Blueprint $table) {
            $table->dropColumn('local_content');
            $table->timestamp('consumed_at')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('merge_preview_tokens', function (Blueprint $table) {
            $table->json('local_content')->nullable();
            $table->dropColumn('consumed_at');
        });
    }
};
