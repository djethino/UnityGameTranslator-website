<?php

namespace App\Http\Controllers;

use App\Models\MergePreviewToken;
use App\Models\Translation;
use App\Services\SsePublisher;
use App\Services\TranslationService;
use Illuminate\Http\Request;

class MergeController extends Controller
{
    public function __construct(
        private TranslationService $translationService
    ) {}

    /**
     * Show the merge view for a Main translation.
     * Only the Main owner can access this page.
     */
    public function show(Request $request, string $uuid)
    {
        $user = auth()->user();

        // Verify that the user is the Main owner
        $main = Translation::where('file_uuid', $uuid)
            ->where('visibility', 'public')
            ->where('user_id', $user->id)
            ->with(['game', 'user'])
            ->firstOrFail();

        // Mode: 'edit' = focus on Main only, 'merge' = show branches
        $mode = $request->input('mode', 'merge');

        // Check if branches exist (lightweight count for switcher visibility)
        $hasBranches = Translation::where('file_uuid', $uuid)
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

            $selectedIds = $request->input('branches', $defaultIds);
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
            'hasBranches'
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
        $user = auth()->user();

        $main = Translation::where('file_uuid', $uuid)
            ->where('visibility', 'public')
            ->where('user_id', $user->id)
            ->with('user:id,name')
            ->firstOrFail();

        $mode = $request->input('mode', 'merge');

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
                        'content' => $this->loadTranslationContent($branch),
                    ];
                }
            }
        }

        return response()->json([
            'main' => $this->loadTranslationContent($main),
            'main_owner' => $main->user->name ?? '',
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

        // Verify ownership
        $main = Translation::where('file_uuid', $uuid)
            ->where('visibility', 'public')
            ->where('user_id', $user->id)
            ->firstOrFail();

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
        }
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

        // Must have at least one change
        if (empty($selections) && empty($deletions) && empty($tagChanges)) {
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
        if (!empty($selections)) {
            foreach ($selections as $sel) {
                // Normalize line endings: \r\n -> \n (forms may convert line endings)
                $key = $this->translationService->normalizeContent($sel['key']);
                $value = $this->translationService->normalizeContent($sel['value']);
                $tag = $sel['tag'];
                $source = $sel['source'];

                // Tag rules:
                // - M (Mod UI) and S (Skipped) are preserved as-is (never changed)
                // - Manual edit → becomes H
                // - Tag A (from Main or branch) → becomes V (human validated this AI translation)
                // - Tag H and V stay the same (already human/validated)
                if ($tag !== 'M' && $tag !== 'S') {
                    if ($source === 'manual') {
                        $tag = 'H';
                    } elseif ($tag === 'A') {
                        $tag = 'V';
                    }
                    // H and V from branches keep their original tag
                }

                $content[$key] = ['v' => $value, 't' => $tag];
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

                    // Update with new tag, keep the value
                    $content[$key] = ['v' => $currentValue, 't' => $newTag];
                    $tagChangedCount++;
                }
            }
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
        $main->line_count = count(array_filter(
            array_keys($content),
            fn($k) => !str_starts_with($k, '_')
        ));
        $main->save();

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
        $successMessage = implode(' et ', $messages) . ' appliquée(s).';

        // Preserve query parameters (sort, search, page, filters, branches)
        $queryParams = $request->only([
            'mode', 'sort', 'dir', 'search', 'scope', 'page',
            'branches', 'new_keys', 'difference',
            'human', 'validated', 'ai', 'skipped', 'mod_ui',
        ]);

        return redirect()
            ->route('translations.merge', array_merge(['uuid' => $uuid], array_filter($queryParams, fn($v) => $v !== null)))
            ->with('success', $successMessage);
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
