<?php

namespace App\Http\Controllers;

use App\Models\MergePreviewToken;
use App\Models\Translation;
use App\Notifications\BranchMerged;
use App\Services\SsePublisher;
use App\Services\TranslationService;
use Illuminate\Http\Request;

class MergeController extends Controller
{
    public function __construct(
        private TranslationService $translationService
    ) {}

    /**
     * The caller's own translation in this lineage, Main or branch alike.
     *
     * Correcting one's own lines from the site has nothing to do with owning
     * the Main: a branch author edits their work exactly like anyone else.
     * What stays reserved to the Main is the MERGE view, because branches are
     * only ever visible to the Main they contribute to — a branch never sees
     * another branch. (file_uuid, user_id) is unique, so this matches one row.
     */
    private function ownTranslation(string $uuid): Translation
    {
        return Translation::where('file_uuid', $uuid)
            ->where('user_id', auth()->id())
            ->with(['game', 'user'])
            ->firstOrFail();
    }

    /**
     * Show the editor for the caller's own translation in this lineage.
     * Branches get the edit mode only; merge is the Main's view.
     */
    public function show(Request $request, string $uuid)
    {
        $main = $this->ownTranslation($uuid);
        // Visibility, not isMain(): that helper also requires being the oldest
        // row of the lineage, which says nothing about who owns the Main here
        // (and ties the answer to creation-timestamp ordering).
        $isMain = $main->visibility === 'public';

        // Mode: 'edit' = my own lines only, 'merge' = with branches (Main only).
        // A branch has nothing to merge, so it never leaves edit mode.
        $mode = $isMain ? $request->input('mode', 'merge') : 'edit';

        // Check if branches exist (lightweight count for switcher visibility)
        $hasBranches = $isMain && Translation::where('file_uuid', $uuid)
            ->where('visibility', 'branch')
            ->exists();

        if ($mode === 'edit') {
            // Edit mode: no branches loaded
            $branches = collect();
            $selectedBranches = collect();
        } else {
            // Merge mode: load all branches (best rated first, unreviewed before reviewed)
            $branches = Translation::where('file_uuid', $uuid)
                ->where('visibility', 'branch')
                ->with('user:id,name')
                ->orderByRaw('CASE WHEN reviewed_hash IS NULL OR file_hash != reviewed_hash THEN 0 ELSE 1 END')
                ->orderByDesc('main_rating')
                ->orderBy('updated_at', 'desc')
                ->get();

            // Default: select only unreviewed branches (never reviewed or modified since)
            $defaultIds = $branches->filter(function ($b) {
                return !$b->reviewed_hash || $b->file_hash !== $b->reviewed_hash;
            })->pluck('id')->toArray();

            // "None" has to be distinguishable from "has not chosen yet", and an absent
            // parameter says both. Unticking every box therefore submitted nothing, fell back on
            // the default, and handed back the whole selection — the one button that could not
            // do what it said. The form states that a choice was made; the choice itself may be
            // empty.
            $hasChosen = $request->boolean('branches_chosen');
            $selectedIds = $hasChosen ? $request->input('branches', []) : $request->input('branches', $defaultIds);
            if (is_string($selectedIds)) {
                $selectedIds = explode(',', $selectedIds);
            }
            $selectedIds = array_map('intval', array_filter($selectedIds));
            $selectedBranches = $branches->whereIn('id', $selectedIds);
        }

        // Content, filtering, search, sort and windowing are client-side
        // (shared translation-editor core, same as merge-preview and
        // edit-session): the page only renders the frame and the client
        // fetches the data endpoint below.
        return view('merge.show', compact(
            'main',
            'branches',
            'selectedBranches',
            'uuid',
            'mode',
            'hasBranches',
            'isMain'
        ));
    }

    /**
     * Stream the merge data for the client-side editor: Main content plus
     * the selected branches. Same access rule as show() (Main owner only).
     *
     * GET /translations/{uuid}/merge/data?mode=&branches[]=
     */
    public function data(Request $request, string $uuid)
    {
        $main = $this->ownTranslation($uuid);

        // Same rule as show(): a branch only ever gets its own content
        $mode = $main->visibility === 'public' ? $request->input('mode', 'merge') : 'edit';

        $branchesPayload = [];
        if ($mode !== 'edit') {
            $selectedIds = $request->input('branches', []);
            if (is_string($selectedIds)) {
                $selectedIds = explode(',', $selectedIds);
            }
            $selectedIds = array_map('intval', array_filter((array) $selectedIds));

            if (!empty($selectedIds)) {
                $selectedBranches = Translation::where('file_uuid', $uuid)
                    ->where('visibility', 'branch')
                    ->whereIn('id', $selectedIds)
                    ->with('user:id,name')
                    ->get();

                foreach ($selectedBranches as $branch) {
                    $branchesPayload[] = [
                        'id' => $branch->id,
                        'name' => $branch->user->name ?? '',
                        'human_count' => $branch->human_count,
                        'validated_count' => $branch->validated_count,
                        'ai_count' => $branch->ai_count,
                        // The shares as the model computes them, so a branch's bar cannot say
                        // something else than that same branch's card elsewhere on the site.
                        'shares' => $branch->qualityShares(),
                        'content' => $this->loadTranslationContent($branch),
                        // Settings live in the metadata keys that loadTranslationContent strips,
                        // so they travel apart. Until now a Main could see THAT a branch's fonts
                        // differed but never which one, and accepting every line accepted none
                        // of them — the merge silently kept the Main's own settings.
                        'settings' => $this->translationService->comparableSettingsOf($branch),

                        // What the contributor SAYS about their work, apart from the work.
                        //
                        // 🔴 A contribution can carry a clearer description or the link to the
                        // fonts it needs, and until now the Main had no way to see either: the
                        // merge dealt in lines and file settings only, so those two were written
                        // by their author and read by nobody.
                        //
                        // ⚠ Its OWN link, never getEffectiveResourcesUrl(): a branch with none
                        // shows its Main's, and offering the Main to take back its own link
                        // would be an entry that says nothing.
                        'notes' => $branch->notes,
                        'resources_url' => $branch->resources_url,

                        // What the Main's owner already thinks of this contribution, in stars.
                        // Used to break a tie between two contributions offering the same
                        // quality of work on one line: a reviewer who has judged a contributor
                        // once should not be asked to judge them again line by line.
                        //
                        // Null resets itself when the branch's file changes (Translation's
                        // saving hook), so a rating never speaks for work it never saw.
                        'main_rating' => $branch->main_rating,
                    ];
                }
            }
        }

        return response()->json([
            'main' => $this->loadTranslationContent($main),
            'main_owner' => $main->user->name ?? '',
            'main_settings' => $this->translationService->comparableSettingsOf($main),
            'main_notes' => $main->notes,
            'main_resources_url' => $main->resources_url,
            'branches' => $branchesPayload,
        ], 200, [
            'Cache-Control' => 'no-store, private',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Apply merge selections to the Main translation.
     */
    public function apply(Request $request, string $uuid)
    {
        $user = auth()->user();
        $main = $this->ownTranslation($uuid);

        // Decode JSON-encoded data (sent as JSON strings to avoid Laravel TrimStrings
        // corrupting translation keys that contain leading/trailing whitespace)
        $selections = [];
        $deletions = [];
        $tagChanges = [];

        if ($request->filled('selections_json')) {
            $selections = json_decode($request->input('selections_json'), true);
            if (!is_array($selections)) {
                return back()->withErrors(['error' => 'Invalid selections data.']);
            }
        }
        if ($request->filled('deletions_json')) {
            $deletions = json_decode($request->input('deletions_json'), true);
            if (!is_array($deletions)) {
                return back()->withErrors(['error' => 'Invalid deletions data.']);
            }
        }
        if ($request->filled('tag_changes_json')) {
            $tagChanges = json_decode($request->input('tag_changes_json'), true);
            if (!is_array($tagChanges)) {
                return back()->withErrors(['error' => 'Invalid tag changes data.']);
            }
        }

        // Validate structure
        foreach ($selections as $sel) {
            if (!isset($sel['key'], $sel['tag'], $sel['source']) || !array_key_exists('value', $sel)) {
                return back()->withErrors(['error' => 'Invalid selection entry.']);
            }
            if (!in_array($sel['tag'], ['H', 'A', 'V', 'M', 'S'], true)) {
                return back()->withErrors(['error' => 'Invalid tag value.']);
            }
            // ⚠ A tag set by hand now rides in its row's own entry (`source: tagchange`), where
            // resolveMergedTag writes it AS IS — no H forcing, no A → V promotion. The dropdown
            // offers three gestures and no more, and that restriction used to live on the separate
            // channel: it moves here rather than being lost with it.
            if ($sel['source'] === 'tagchange' && !in_array($sel['tag'], ['V', 'A', 'S'], true)) {
                return back()->withErrors(['error' => 'Invalid tag change value.']);
            }
        }
        // ⚠ **The old separate channel, kept for reading only.** The page stopped filling it: a tag
        // set by hand travels in its row's own entry above. What still arrives here comes from a tab
        // that was open with the previous script when this shipped, and dropping it would lose that
        // person's work in silence. Nothing new is built on it.
        foreach ($tagChanges as $change) {
            if (!isset($change['key'], $change['tag']) || !array_key_exists('value', $change)) {
                return back()->withErrors(['error' => 'Invalid tag change entry.']);
            }
            // V = validate, A = invalidate, S = skip — the three explicit
            // tag gestures offered by every editor's dropdown
            if (!in_array($change['tag'], ['V', 'A', 'S'], true)) {
                return back()->withErrors(['error' => 'Invalid tag change value.']);
            }
        }

        // Settings taken from a branch: { "<branchId>": { "fonts:Title": true, ... } }.
        // Keyed by branch because a merge can carry several at once, and two of them may hold
        // the same setting with different values — the Main has to say WHOSE it takes.
        $settingChoices = [];
        if ($request->filled('settings_json')) {
            $settingChoices = json_decode($request->input('settings_json'), true);
            if (!is_array($settingChoices)) {
                return back()->withErrors(['error' => 'Invalid settings data.']);
            }
        }

        // What the Main takes of what its contributors SAY about their work: { "notes": "…" }.
        //
        // ⚠ Values, not "take branch N's". The page pre-fills each field with the contribution's
        // wording and lets the Main adjust it before taking — a description written for a
        // contribution rarely reads right at the head of a lineage. So what arrives here is the
        // final text, exactly as for a translation line edited in the same screen.
        $publication = [];
        if ($request->filled('publication_json')) {
            $publication = json_decode($request->input('publication_json'), true);
            if (!is_array($publication)) {
                return back()->withErrors(['error' => 'Invalid publication data.']);
            }
        }

        // Must have at least one change
        if (empty($selections) && empty($deletions) && empty($tagChanges) && empty($settingChoices)
            && empty($publication)) {
            return back()->withErrors(['error' => 'No changes to apply.']);
        }

        // Load current Main content
        $path = $main->getSafeFilePath();
        if (!$path || !file_exists($path)) {
            return back()->withErrors(['error' => 'Translation file not found.']);
        }

        $rawContent = file_get_contents($path);
        // Normalize line endings in file content before parsing
        $rawContent = $this->translationService->normalizeContent($rawContent);
        $content = json_decode($rawContent, true);
        if (!is_array($content)) {
            return back()->withErrors(['error' => 'Invalid translation file format.']);
        }

        // Apply modifications
        $modifiedCount = 0;
        $conflicts = [];
        if (!empty($selections)) {
            foreach ($selections as $sel) {
                // Normalize line endings: \r\n -> \n (forms may convert line endings)
                $key = $this->translationService->normalizeContent($sel['key']);
                $value = $this->translationService->normalizeContent($sel['value']);
                $tag = $sel['tag'];
                $source = $sel['source'];

                // Concurrent change guard.
                //
                // The file is re-read at save time, so untouched lines already
                // keep whatever arrived meanwhile — but a line edited on BOTH
                // sides used to be overwritten in silence. That is the normal
                // multi-device case: correcting on a laptop while the game
                // uploads captures from the desktop.
                //
                // The page sends the value it loaded ("base"), which is exactly
                // the common ancestor: if the file no longer holds it, someone
                // else wrote there first. Such lines are left alone and
                // reported; everything else still applies, so a conflict on one
                // line never costs the work done on the others.
                if (array_key_exists('base', $sel)) {
                    $base = $this->translationService->normalizeContent((string) $sel['base']);
                    $current = isset($content[$key])
                        ? (is_array($content[$key]) ? ($content[$key]['v'] ?? '') : $content[$key])
                        : null;

                    if ($current !== null && $current !== $base && $current !== $value) {
                        $conflicts[] = $key;
                        continue;
                    }
                }

                // Shared rule — see TranslationService::resolveMergedTag.
                //
                // ⚠ `auto` means the screen answered this row rather than the owner: it is kept as
                // it is, tag untouched. Absent on an older client, which only ever sent rows
                // somebody had picked — so the default is "claimed", and nothing changes for them.
                $tag = TranslationService::resolveMergedTag(
                    $tag, $source, claimed: !($sel['auto'] ?? false));

                // rebuildEntry keeps the ordering index "i" of the existing entry
                $content[$key] = TranslationService::rebuildEntry($content[$key] ?? null, $value, $tag);
                $modifiedCount++;
            }
        }

        // Apply deletions
        $deletedCount = 0;
        if (!empty($deletions)) {
            foreach ($deletions as $key) {
                // Normalize line endings: \r\n -> \n
                $key = $this->translationService->normalizeContent($key);
                // Only delete non-metadata keys that exist
                if (!str_starts_with($key, '_') && isset($content[$key])) {
                    unset($content[$key]);
                    $deletedCount++;
                }
            }
        }

        // Apply tag changes (skip/invalidate)
        // Tag changes are explicit tag modifications without changing the value
        $tagChangedCount = 0;
        if (!empty($tagChanges)) {
            foreach ($tagChanges as $change) {
                $key = $this->translationService->normalizeContent($change['key']);
                $newTag = $change['tag'];
                $value = $this->translationService->normalizeContent($change['value']);

                // Only process non-metadata keys that exist
                if (!str_starts_with($key, '_') && isset($content[$key])) {
                    // Get current value
                    $currentValue = is_array($content[$key])
                        ? ($content[$key]['v'] ?? '')
                        : $content[$key];

                    // Update with new tag, keep the value (and the ordering index "i")
                    $content[$key] = TranslationService::rebuildEntry($content[$key], $currentValue, $newTag);
                    $tagChangedCount++;
                }
            }
        }

        // Settings taken from branches. Each winning entry is COPIED from the branch's own file
        // — what the page showed is a readable summary that drops fields it never renders.
        // Branches are applied in the order the Main picked them; two branches offering the same
        // setting resolve by that order, which is the only one the Main expressed.
        $settingsTaken = 0;
        foreach ($settingChoices as $branchId => $keys) {
            if (!is_array($keys) || empty($keys)) {
                continue;
            }

            // Only a branch of THIS translation, and only one the Main owns the lineage of
            $branch = Translation::where('file_uuid', $uuid)
                ->where('visibility', 'branch')
                ->where('id', (int) $branchId)
                ->first();
            if (!$branch) {
                continue;
            }

            $branchPath = $branch->getSafeFilePath();
            if (!$branchPath || !file_exists($branchPath)) {
                continue;
            }

            $branchJson = json_decode(
                $this->translationService->normalizeContent(file_get_contents($branchPath)),
                true
            );
            if (!is_array($branchJson)) {
                continue;
            }

            // applySettingSelections copies from its second argument when told 'local'
            $picks = [];
            foreach (array_keys($keys) as $settingKey) {
                $picks[$settingKey] = 'local';
            }

            $content = $this->translationService->applySettingSelections($content, $branchJson, $picks);
            $settingsTaken += count($picks);
        }

        // Save the file
        $jsonFlags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        file_put_contents($path, json_encode($content, $jsonFlags));

        // Recalculate counters and hash
        $main->file_hash = $main->computeHash();
        $tagCounts = Translation::extractTagCounts($content);
        $main->human_count = $tagCounts['human_count'];
        $main->validated_count = $tagCounts['validated_count'];
        $main->ai_count = $tagCounts['ai_count'];
        $main->capture_count = $tagCounts['capture_count'];
        $main->skipped_count = $tagCounts['skipped_count'];
        $main->line_count = count(array_filter(
            array_keys($content),
            fn($k) => !str_starts_with($k, '_')
        ));

        // ⚠ Two fields and no more. `status` is deliberately absent: whether a translation is
        // finished descends from the Main to its contributions, never the other way, and every
        // other write path in the project enforces that. A merge is not the place to reverse it.
        //
        // ⚠ Validated here rather than trusted: this text goes on the Main's public page, and it
        // arrives from a form. Same limits as everywhere else it can be written.
        if (isset($publication['notes']) && is_string($publication['notes'])) {
            $main->notes = mb_substr($publication['notes'], 0, 1000) ?: null;
        }
        if (isset($publication['resources_url']) && is_string($publication['resources_url'])) {
            $url = trim($publication['resources_url']);
            if ($url === '') {
                $main->resources_url = null;
            } elseif (filter_var($url, FILTER_VALIDATE_URL)
                      && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)
                      && mb_strlen($url) <= 2048) {
                $main->resources_url = $url;
            } else {
                return back()->withErrors(['error' => 'Invalid resources URL.']);
            }
        }

        $main->save();

        // Tell each contributor whose lines were actually merged (per-branch counts
        // from the selections' source markers: 'branch_{id}')
        $mergedPerBranch = [];
        foreach ($selections as $sel) {
            if (preg_match('/^branch_(\d+)$/', $sel['source'] ?? '', $m)) {
                $branchId = (int) $m[1];
                $mergedPerBranch[$branchId] = ($mergedPerBranch[$branchId] ?? 0) + 1;
            }
        }
        if (!empty($mergedPerBranch)) {
            $branches = Translation::whereIn('id', array_keys($mergedPerBranch))
                ->where('file_uuid', $uuid)
                ->with('user')
                ->get();
            foreach ($branches as $branch) {
                // Stamped on the BRANCH, not on the Main: the question it answers is "has this
                // contribution ever been taken in", and it is asked from the contributor's side.
                // The column was added with the lineage migration and nothing had ever written
                // it, so "the Main is ignoring you" could not be told apart from "the Main has
                // not merged anything yet" — the difference between being overlooked and being
                // early. timestamps stay off: merging is not a content change, and touching
                // updated_at here would move the branch in every list ordered by freshness.
                $branch->timestamps = false;
                $branch->merged_at = now();
                // A running total, not an inventory: a line later replaced by another branch or
                // rewritten by the Main still counts. What is measured is a contribution over
                // time, not what survives of it in today's file.
                $branch->merged_lines_total += $mergedPerBranch[$branch->id];
                $branch->save();

                if ($branch->user && $branch->user_id !== $user->id) {
                    $branch->user->notify(new BranchMerged($main, $mergedPerBranch[$branch->id]));
                }
            }
        }

        // Signal SSE via Redis pub/sub — Node.js relays to connected mods
        $activeTokens = MergePreviewToken::where('translation_id', $main->id)
            ->where('expires_at', '>', now())
            ->get();
        foreach ($activeTokens as $mergeToken) {
            SsePublisher::mergeCompleted($mergeToken->token, [
                'translation_id' => $main->id,
                'file_hash' => $main->file_hash,
                'line_count' => $main->line_count,
            ]);
        }
        SsePublisher::translationUpdated($main->id, [
            'file_hash' => $main->file_hash,
            'line_count' => $main->line_count,
            'vote_count' => $main->vote_count,
            'updated_at' => $main->updated_at->toIso8601String(),
        ]);

        // Build success message
        $messages = [];
        if ($modifiedCount > 0) {
            $messages[] = "{$modifiedCount} modification(s)";
        }
        if ($deletedCount > 0) {
            $messages[] = "{$deletedCount} suppression(s)";
        }
        if ($tagChangedCount > 0) {
            $messages[] = "{$tagChangedCount} changement(s) de tag";
        }
        $successMessage = $messages
            ? implode(' et ', $messages) . ' appliquée(s).'
            : null;

        // Preserve query parameters (sort, search, page, filters, branches)
        $queryParams = $request->only([
            'mode', 'sort', 'dir', 'search', 'scope', 'page',
            'branches', 'new_keys', 'difference',
            'human', 'validated', 'ai', 'skipped', 'mod_ui',
        ]);

        $redirect = redirect()
            ->route('translations.merge', array_merge(['uuid' => $uuid], array_filter($queryParams, fn($v) => $v !== null)));

        if ($successMessage) {
            $redirect->with('success', $successMessage);
        }

        // Named, not just counted: knowing WHICH lines were left alone is the
        // difference between "check everything again" and "look at these three"
        if ($conflicts) {
            $redirect->with('warning', trans_choice('merge.conflicts_skipped', count($conflicts), [
                'count' => count($conflicts),
                'keys' => collect($conflicts)->take(5)->implode(', ')
                    . (count($conflicts) > 5 ? '…' : ''),
            ]));
        }

        return $redirect;
    }

    /**
     * Load translation content from file, excluding metadata keys.
     */
    private function loadTranslationContent(Translation $translation): array
    {
        $path = $translation->getSafeFilePath();
        if (!$path || !file_exists($path)) {
            return [];
        }

        $rawContent = file_get_contents($path);
        // Normalize line endings to prevent key mismatches
        $rawContent = $this->translationService->normalizeContent($rawContent);
        $content = json_decode($rawContent, true);
        if (!is_array($content)) {
            return [];
        }

        // Filter out metadata keys (starting with _)
        return array_filter(
            $content,
            fn($k) => !str_starts_with($k, '_'),
            ARRAY_FILTER_USE_KEY
        );
    }






    /**
     * Rate a branch translation (Main owner only).
     * Stores the rating and the hash of the branch at the time of review.
     */
    public function rateBranch(Request $request, Translation $translation)
    {
        $user = auth()->user();

        // Get the Main translation for this branch
        $main = $translation->getMain();

        // Verify the current user is the Main owner
        if (!$main || $main->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'error' => __('rating.not_main_owner'),
            ], 403);
        }

        // Verify this is actually a branch (not the Main itself)
        if ($translation->id === $main->id) {
            return response()->json([
                'success' => false,
                'error' => __('rating.cannot_rate_main'),
            ], 400);
        }

        // Validate rating (1-5 or null to clear)
        $validated = $request->validate([
            'rating' => 'nullable|integer|min:1|max:5',
        ]);

        $rating = $validated['rating'] ?? null;

        // Update the branch with the rating
        $translation->main_rating = $rating;
        $translation->reviewed_hash = $rating !== null ? $translation->file_hash : null;
        $translation->save();

        return response()->json([
            'success' => true,
            'rating' => $translation->main_rating,
            'reviewed_hash' => $translation->reviewed_hash,
        ]);
    }
}
