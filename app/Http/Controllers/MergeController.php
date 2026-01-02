<?php

namespace App\Http\Controllers;

use App\Models\Translation;
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

        // Load all branches for this UUID
        $branches = Translation::where('file_uuid', $uuid)
            ->where('visibility', 'branch')
            ->with('user:id,name')
            ->orderBy('updated_at', 'desc')
            ->get();

        // Get selected branch IDs from query params (default: all)
        $selectedIds = $request->input('branches', $branches->pluck('id')->toArray());
        if (is_string($selectedIds)) {
            $selectedIds = explode(',', $selectedIds);
        }
        $selectedIds = array_map('intval', $selectedIds);
        $selectedBranches = $branches->whereIn('id', $selectedIds);

        // Load Main content
        $mainContent = $this->loadTranslationContent($main);

        // Load selected branches content
        $branchContents = [];
        foreach ($selectedBranches as $branch) {
            $branchContents[$branch->id] = $this->loadTranslationContent($branch);
        }

        // Build unified list of all keys
        $allKeys = $this->getAllKeys($mainContent, $branchContents);

        // Apply filters
        $filters = [
            'new_keys' => $request->boolean('new_keys'),
            'difference' => $request->boolean('difference'),
            'human' => $request->boolean('human'),
            'ai' => $request->boolean('ai'),
            'validated' => $request->boolean('validated'),
            'mod_ui' => $request->boolean('mod_ui'),
            'skipped' => $request->boolean('skipped'),
        ];

        $filteredKeys = $this->applyFilters($allKeys, $mainContent, $branchContents, $filters);

        // Apply search
        $search = $request->input('search');
        if ($search) {
            $searchLower = mb_strtolower($search);
            $filteredKeys = array_values(array_filter($filteredKeys, function ($key) use ($searchLower, $mainContent, $branchContents) {
                // Check key
                if (mb_stripos($key, $searchLower) !== false) {
                    return true;
                }
                // Check main value
                if (isset($mainContent[$key])) {
                    $mainValue = $this->extractValue($mainContent[$key]);
                    if (mb_stripos($mainValue, $searchLower) !== false) {
                        return true;
                    }
                }
                // Check branch values
                foreach ($branchContents as $content) {
                    if (isset($content[$key])) {
                        $branchValue = $this->extractValue($content[$key]);
                        if (mb_stripos($branchValue, $searchLower) !== false) {
                            return true;
                        }
                    }
                }
                return false;
            }));
        }

        // Apply sorting
        $sortColumn = $request->input('sort', 'key');
        $sortDir = $request->input('dir', 'asc');
        $filteredKeys = $this->applySorting($filteredKeys, $mainContent, $sortColumn, $sortDir);

        // Pagination
        $page = max(1, (int) $request->input('page', 1));
        $perPage = 100;
        $totalKeys = count($filteredKeys);
        $totalPages = max(1, ceil($totalKeys / $perPage));
        $page = min($page, $totalPages);
        $pagedKeys = array_slice($filteredKeys, ($page - 1) * $perPage, $perPage);

        return view('merge.show', compact(
            'main',
            'branches',
            'selectedBranches',
            'mainContent',
            'branchContents',
            'pagedKeys',
            'page',
            'perPage',
            'totalKeys',
            'totalPages',
            'filters',
            'uuid'
        ));
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

        // Validate selections, deletions, and tag changes
        $request->validate([
            'selections' => 'nullable|array',
            'selections.*.key' => 'required|string',
            'selections.*.value' => 'present|string',
            'selections.*.tag' => 'required|in:H,A,V,M,S',
            'selections.*.source' => 'required|string',
            'deletions' => 'nullable|array',
            'deletions.*' => 'string',
            'tagChanges' => 'nullable|array',
            'tagChanges.*.key' => 'required|string',
            'tagChanges.*.tag' => 'required|in:A,S', // Only A (invalidate) and S (skip) are allowed
            'tagChanges.*.value' => 'present|string',
        ]);

        // Must have at least one change
        if (empty($request->selections) && empty($request->deletions) && empty($request->tagChanges)) {
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
        if (!empty($request->selections)) {
            foreach ($request->selections as $sel) {
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
        if (!empty($request->deletions)) {
            foreach ($request->deletions as $key) {
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
        if (!empty($request->tagChanges)) {
            foreach ($request->tagChanges as $change) {
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

        return redirect()
            ->route('translations.merge', ['uuid' => $uuid])
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
     * Get all unique keys from Main and branches.
     */
    private function getAllKeys(array $mainContent, array $branchContents): array
    {
        $keys = array_keys($mainContent);

        foreach ($branchContents as $content) {
            $keys = array_merge($keys, array_keys($content));
        }

        $keys = array_unique($keys);
        sort($keys); // Sort alphabetically for consistency

        return $keys;
    }

    /**
     * Apply filters to the key list.
     */
    private function applyFilters(
        array $allKeys,
        array $mainContent,
        array $branchContents,
        array $filters
    ): array {
        // If no filters active, return all keys
        if (!array_filter($filters)) {
            return $allKeys;
        }

        return array_values(array_filter($allKeys, function ($key) use ($mainContent, $branchContents, $filters) {
            $mainEntry = $mainContent[$key] ?? null;
            $mainValue = $this->extractValue($mainEntry);
            $mainTag = $this->extractTag($mainEntry);

            // Check each filter
            $matches = false;

            // New keys: exists in at least one branch but not in Main
            if ($filters['new_keys']) {
                if ($mainEntry === null) {
                    foreach ($branchContents as $content) {
                        if (isset($content[$key])) {
                            $matches = true;
                            break;
                        }
                    }
                }
            }

            // Difference: value differs between Main and at least one branch
            if ($filters['difference']) {
                foreach ($branchContents as $content) {
                    if (isset($content[$key])) {
                        $branchValue = $this->extractValue($content[$key]);
                        if ($branchValue !== $mainValue) {
                            $matches = true;
                            break;
                        }
                    }
                }
            }

            // Tag filters (check Main and branches)
            if ($filters['human']) {
                if ($mainTag === 'H') {
                    $matches = true;
                }
                foreach ($branchContents as $content) {
                    if (isset($content[$key]) && $this->extractTag($content[$key]) === 'H') {
                        $matches = true;
                        break;
                    }
                }
            }

            if ($filters['ai']) {
                if ($mainTag === 'A') {
                    $matches = true;
                }
                foreach ($branchContents as $content) {
                    if (isset($content[$key]) && $this->extractTag($content[$key]) === 'A') {
                        $matches = true;
                        break;
                    }
                }
            }

            if ($filters['validated']) {
                if ($mainTag === 'V') {
                    $matches = true;
                }
                foreach ($branchContents as $content) {
                    if (isset($content[$key]) && $this->extractTag($content[$key]) === 'V') {
                        $matches = true;
                        break;
                    }
                }
            }

            if ($filters['mod_ui']) {
                if ($mainTag === 'M') {
                    $matches = true;
                }
                foreach ($branchContents as $content) {
                    if (isset($content[$key]) && $this->extractTag($content[$key]) === 'M') {
                        $matches = true;
                        break;
                    }
                }
            }

            if ($filters['skipped']) {
                if ($mainTag === 'S') {
                    $matches = true;
                }
                foreach ($branchContents as $content) {
                    if (isset($content[$key]) && $this->extractTag($content[$key]) === 'S') {
                        $matches = true;
                        break;
                    }
                }
            }

            return $matches;
        }));
    }

    /**
     * Apply sorting to the key list.
     */
    private function applySorting(array $keys, array $mainContent, string $column, string $direction): array
    {
        $multiplier = ($direction === 'desc') ? -1 : 1;

        usort($keys, function ($a, $b) use ($mainContent, $column, $multiplier) {
            switch ($column) {
                case 'mainTag':
                    $valA = isset($mainContent[$a]) ? $this->extractTag($mainContent[$a]) : '';
                    $valB = isset($mainContent[$b]) ? $this->extractTag($mainContent[$b]) : '';
                    break;
                case 'mainValue':
                    $valA = isset($mainContent[$a]) ? mb_strtolower($this->extractValue($mainContent[$a])) : '';
                    $valB = isset($mainContent[$b]) ? mb_strtolower($this->extractValue($mainContent[$b])) : '';
                    break;
                case 'key':
                default:
                    $valA = mb_strtolower($a);
                    $valB = mb_strtolower($b);
                    break;
            }

            return strcmp($valA, $valB) * $multiplier;
        });

        return $keys;
    }

    /**
     * Extract value from entry (supports both old string format and new object format).
     */
    private function extractValue($entry): string
    {
        if ($entry === null) {
            return '';
        }
        if (is_array($entry)) {
            return $entry['v'] ?? '';
        }
        return (string) $entry;
    }

    /**
     * Extract tag from entry (defaults to A for old format).
     */
    private function extractTag($entry): string
    {
        if ($entry === null) {
            return 'A';
        }
        if (is_array($entry)) {
            return $entry['t'] ?? 'A';
        }
        return 'A'; // Old format = AI by default
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
