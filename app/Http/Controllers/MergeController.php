<?php

namespace App\Http\Controllers;

use App\Models\Translation;
use Illuminate\Http\Request;

class MergeController extends Controller
{
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
        ];

        $filteredKeys = $this->applyFilters($allKeys, $mainContent, $branchContents, $filters);

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

        // Validate selections
        $request->validate([
            'selections' => 'required|array|min:1',
            'selections.*.key' => 'required|string',
            'selections.*.value' => 'present|string',
            'selections.*.tag' => 'required|in:H,A,V',
            'selections.*.source' => 'required|string',
        ]);

        // Load current Main content
        $path = $main->getSafeFilePath();
        if (!$path || !file_exists($path)) {
            return back()->withErrors(['error' => 'Translation file not found.']);
        }

        $content = json_decode(file_get_contents($path), true);
        if (!is_array($content)) {
            return back()->withErrors(['error' => 'Invalid translation file format.']);
        }

        // Apply modifications
        $modifiedCount = 0;
        foreach ($request->selections as $sel) {
            $key = $sel['key'];
            $value = $sel['value'];
            $tag = $sel['tag'];
            $source = $sel['source'];

            // HCA rules:
            // - If selecting from branch and tag is A → becomes V (validated by human)
            // - If manual edit → becomes H
            // - H and V stay the same
            if (str_starts_with($source, 'branch_') && $tag === 'A') {
                $tag = 'V';
            }
            if ($source === 'manual') {
                $tag = 'H';
            }

            $content[$key] = ['v' => $value, 't' => $tag];
            $modifiedCount++;
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
        $main->line_count = count(array_filter(
            array_keys($content),
            fn($k) => !str_starts_with($k, '_')
        ));
        $main->save();

        return redirect()
            ->route('translations.merge', ['uuid' => $uuid])
            ->with('success', "{$modifiedCount} modification(s) appliquée(s).");
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

        $content = json_decode(file_get_contents($path), true);
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

            return $matches;
        }));
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
}
