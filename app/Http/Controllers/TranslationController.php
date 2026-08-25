<?php

namespace App\Http\Controllers;

use App\Models\AnalyticsEvent;
use App\Models\AuditLog;
use App\Models\Game;
use App\Models\MergePreviewToken;
use App\Models\Translation;
use App\Services\CatalogStore;
use App\Services\SsePublisher;
use App\Services\TranslationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TranslationController extends Controller
{
    public function create()
    {
        return view('translations.create', ['languages' => CatalogStore::languageNames()]);
    }

    public function store(Request $request, TranslationService $service)
    {
        $languages = CatalogStore::languageNames();

        $request->validate([
            'game_id' => 'nullable|exists:games,id',
            'game_name' => 'required_without:game_id|string|max:255',
            'source_language' => ['required', 'string', 'in:' . implode(',', $languages)],
            'target_language' => ['required', 'string', 'in:' . implode(',', $languages)],
            'status' => 'nullable|in:in_progress,complete', // Optional - branches inherit from Main
            // The other declaration a Main makes about its own work. Asked here as well as on the
            // edit form: it is part of publishing, and finding it only afterwards means the first
            // contributor is turned away by a decision its author never knowingly took.
            'accepts_branches' => 'nullable|boolean',
            'notes' => 'nullable|string|max:1000',
            'file' => 'required|file|mimes:json|max:102400', // 100MB max
            'game_source' => 'required_without:game_id|string|in:igdb,rawg',
            'game_external_id' => 'required_without:game_id|integer',
            'game_image_url' => 'nullable|url|max:500',
        ]);

        // Parse and validate content (includes normalization)
        $content = file_get_contents($request->file('file')->getRealPath());
        try {
            $parsed = $service->parseAndValidate($content);
        } catch (\InvalidArgumentException $e) {
            $message = $e->getMessage();
            // Check if it's a JSON-encoded error with details
            $decoded = json_decode($message, true);
            if (is_array($decoded) && isset($decoded['details'])) {
                return back()->withErrors(['file' => $decoded['error'] . ' ' . implode(', ', array_slice($decoded['details'], 0, 3))]);
            }

            return back()->withErrors(['file' => $message]);
        }

        // A file that translates nothing, asked about once before it is published.
        //
        // Capture mode collects the game's own text on purpose, as a starting point. Published as
        // it stands it looks like a translation from the outside and hands the original words
        // back — which has happened, and the next person built their own work on top of it. The
        // author is the only one who knows whether that is what they meant, so this asks rather
        // than refuses. Same question the mod asks at the same moment.
        //
        // The file has to be picked again to confirm, and that is not an oversight: this is the
        // one place where a second of friction is cheaper than a catalogue entry that changes
        // nothing in anyone's game.
        $tagCounts = $parsed['tag_counts'];
        $translatedLines = ($tagCounts['human_count'] ?? 0) + ($tagCounts['validated_count'] ?? 0)
            + ($tagCounts['ai_count'] ?? 0);

        if ($translatedLines === 0 && ($tagCounts['capture_count'] ?? 0) > 0 && !$request->boolean('publish_empty')) {
            return back()
                ->withInput()
                ->with('confirm_empty', true)
                ->withErrors(['file' => __('upload.empty_warning', [
                    'count' => number_format($tagCounts['capture_count']),
                ])]);
        }

        $fileUuid = $parsed['uuid'];
        $userId = auth()->id();

        // Check for existing translation with same UUID (UPDATE case)
        $existingTranslation = $service->findUserTranslation($fileUuid, $userId);

        // Determine ownership and visibility
        $ownership = $service->determineOwnership($fileUuid, $userId);

        // Same two doors as the API path — see the note there. This one is the website's own
        // upload form, and it must not be the way round the decision.
        if (isset($ownership['refused'])) {
            return back()->withErrors(['file' => $ownership['refused']]);
        }

        if ($existingTranslation && $existingTranslation->isFrozenBranch()) {
            return back()->withErrors(['file' =>
                'The translation you contribute to no longer accepts contributions. '
                . 'Your work is untouched — turn it into your own version to carry on.']);
        }

        $originalTranslation = $existingTranslation ? null : $ownership['original'];
        $visibility = $existingTranslation ? $existingTranslation->visibility : $ownership['visibility'];
        $parentId = $existingTranslation ? $existingTranslation->parent_id : $ownership['parent_id'];

        // Resolve languages
        try {
            $languages = $service->resolveLanguages(
                $request->source_language,
                $request->target_language,
                $existingTranslation,
                $originalTranslation
            );
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        // Resolve game by case (game_id is part of a translation's identity once created):
        //   UPDATE → keep the translation's existing game (never mutate)
        //   FORK   → inherit the parent's game (a fork is by definition same-game)
        //   NEW    → resolve via findOrCreateGame (form's game_id / external_id / etc.)
        if ($existingTranslation) {
            $game = $existingTranslation->game;
        } elseif ($originalTranslation) {
            $game = $originalTranslation->game;
        } else {
            $game = $this->findOrCreateGame($request);
        }

        // Note: 'type' is now a computed attribute from HVASM stats (getTypeAttribute)

        // Determine status: branches inherit from Main or use 'in_progress'
        // Only Main owners can set/change status
        $isBranch = $visibility === 'branch' || ($existingTranslation && $existingTranslation->visibility === 'branch');
        if ($isBranch) {
            // Branches: inherit status from Main or keep existing
            if ($existingTranslation) {
                $status = $existingTranslation->status;
            } else {
                // New branch: inherit from Main or default to in_progress
                $main = $originalTranslation ?? Translation::where('file_uuid', $fileUuid)
                    ->where('visibility', 'public')
                    ->first();
                $status = $main ? $main->status : 'in_progress';
            }
        } else {
            // Main owner: can set status (default to in_progress if not provided)
            $status = $request->status ?? ($existingTranslation ? $existingTranslation->status : 'in_progress');
        }

        // Store file
        $fileName = $service->storeFile($parsed['normalized_content'], $fileUuid);

        if ($existingTranslation) {
            // Read before the update, so the closing transition can be spotted after it.
            $wasOpen = (bool) $existingTranslation->accepts_branches;

            // UPDATE: Delete old file and update record
            $service->deleteFile($existingTranslation->file_path);

            // game_id intentionally omitted — see resolution block above. The translation's
            // game is fixed at creation; subsequent uploads cannot change it via this path.
            $existingTranslation->update([
                'line_count' => $parsed['line_count'],
                'human_count' => $parsed['tag_counts']['human_count'],
                'validated_count' => $parsed['tag_counts']['validated_count'],
                'ai_count' => $parsed['tag_counts']['ai_count'],
                'capture_count' => $parsed['tag_counts']['capture_count'],
                'skipped_count' => $parsed['tag_counts']['skipped_count'],
                'status' => $status,
                'notes' => $request->notes,
                'file_path' => $fileName,
                'file_hash' => $parsed['file_hash'],
                'content_hash' => $parsed['content_hash'],
                'font_config' => $parsed['font_config'],
                'settings_summary' => $parsed['settings_summary'],

                // ⚠ Only a Main answers this, and only when the form actually asked. An absent
                // checkbox is "not asked" and must keep what was already decided — reading it as
                // false would close a lineage every time somebody re-uploaded from a form that
                // did not carry the question.
                'accepts_branches' => !$isBranch && $request->has('accepts_branches')
                    ? $request->boolean('accepts_branches')
                    : $existingTranslation->accepts_branches,
            ]);

            // Same transition as the settings form: closing is what the contributors have to hear
            // about, and this path could close a lineage just as well.
            if ($wasOpen && !$existingTranslation->fresh()->accepts_branches) {
                $existingTranslation->notifyBranchesOfClosure();
            }

            AuditLog::logTranslationUpload($userId, $existingTranslation->id, [
                'game_id' => $game->id,
                'game_name' => $game->name,
                'source_language' => $languages['source'],
                'target_language' => $languages['target'],
                'line_count' => $parsed['line_count'],
                'is_update' => true,
            ], $request);

            return redirect()->route('games.show', $game)
                ->with('success', 'Translation updated successfully!');
        }

        // NEW or BRANCH: Create new translation
        $translation = Translation::create([
            'game_id' => $game->id,
            'user_id' => $userId,
            'parent_id' => $parentId,
            'visibility' => $visibility,
            'source_language' => $languages['source'],
            'target_language' => $languages['target'],
            'line_count' => $parsed['line_count'],
            'human_count' => $parsed['tag_counts']['human_count'],
            'validated_count' => $parsed['tag_counts']['validated_count'],
            'ai_count' => $parsed['tag_counts']['ai_count'],
            'capture_count' => $parsed['tag_counts']['capture_count'],
            'skipped_count' => $parsed['tag_counts']['skipped_count'],
            'status' => $status,
            'notes' => $request->notes,

            // 🔴 Default closed, and a branch never decides it. Keeping a translation open to
            // contributions is work nobody agreed to by publishing.
            'accepts_branches' => $visibility !== 'branch' && $request->boolean('accepts_branches'),
            'file_path' => $fileName,
            'file_uuid' => $fileUuid,
            'file_hash' => $parsed['file_hash'],
            'content_hash' => $parsed['content_hash'],
            'font_config' => $parsed['font_config'],
            'settings_summary' => $parsed['settings_summary'],
        ]);

        AuditLog::logTranslationUpload($userId, $translation->id, [
            'game_id' => $game->id,
            'game_name' => $game->name,
            'source_language' => $languages['source'],
            'target_language' => $languages['target'],
            'line_count' => $parsed['line_count'],
            'is_fork' => $parentId !== null,
        ], $request);

        return redirect()->route('games.show', $game)
            ->with('success', 'Translation uploaded successfully!');
    }

    /**
     * Read-only view of a translation's lines, for anyone.
     *
     * Before this, the only way to know what was inside a translation was to download it and
     * open the file — so the choice between three translations of the same game was made on
     * counts and a coloured bar alone. Nothing new is disclosed: the file has always been
     * downloadable by anyone, and one rule decides both — Translation::isReadableBy, which
     * already existed and which the download route had been re-implementing inline.
     *
     * No download count is incremented here: looking is not taking, and counting it would
     * inflate the very number people use to judge a translation.
     */
    public function view(Translation $translation)
    {
        if (!$translation->isReadableBy(auth()->user())) {
            abort(403, 'A branch is visible to whoever wrote it, and to the Main owner for merging.');
        }

        $translation->load(['game', 'user', 'originAuthor']);

        return view('translations.view', ['translation' => $translation]);
    }

    /**
     * The lines themselves, for the read-only viewer.
     *
     * Sent whole, exactly as the three editors are: filtering, searching, sorting and windowing
     * belong to the shared editor core, which does them live in the browser. A server-paginated
     * copy of the same features was the first attempt and it made the reading screens behave
     * differently from the editing ones — no highlighting, a search that needed Enter, pages
     * instead of "show more".
     */
    public function viewData(Translation $translation, TranslationService $service)
    {
        if (!$translation->isReadableBy(auth()->user())) {
            abort(403, 'A branch is visible to whoever wrote it, and to the Main owner for merging.');
        }

        // The reading itself lives on the model (fileLines): the admin screens serve the same
        // payload from their own route, and two copies of "strip the underscore keys" would
        // eventually disagree about what counts as a line.
        $lines = $translation->fileLines();

        return response()->json([
            'ok' => $lines !== null,
            'content' => (object) ($lines ?? []),

            // 🔴 What the file carries besides its lines, under the names the editors already
            // use. This screen is where somebody decides whether to TAKE a translation, and it
            // could not tell them which fonts it replaces, which lines it leaves alone or where
            // the images it needs live — the only way to find out was to download it and open it.
            //
            // ⚠ Nothing private travels here: these are settings any downloader already has, and
            // the page itself is behind the same isReadableBy check as the file.
            'main_settings' => $service->comparableSettingsOf($translation),
            'main_notes' => $translation->notes,
            'main_resources_url' => $translation->resources_url,
        ]);
    }

    public function download(Translation $translation)
    {
        // Security: branches are private to the Main owner. The rule itself lives on the model,
        // so the pages that decide whether to OFFER a way in cannot drift from what the server
        // actually allows.
        if (!$translation->isReadableBy(auth()->user())) {
            abort(403, 'A branch is visible to whoever wrote it, and to the Main owner for merging.');
        }

        $translation->incrementDownloads();

        // Track download for analytics
        try {
            $request = request();
            $userAgent = $request->userAgent() ?? '';
            $ip = $request->ip() ?? '0.0.0.0';

            AnalyticsEvent::create([
                'route' => 'translations.download',
                'game_id' => $translation->game_id,
                'country' => null, // Not tracking country for downloads
                'referrer_domain' => AnalyticsEvent::extractReferrerDomain($request->header('Referer')),
                'device' => AnalyticsEvent::detectDevice($userAgent),
                'browser' => AnalyticsEvent::detectBrowser($userAgent),
                'visitor_hash' => AnalyticsEvent::generateVisitorHash($ip, $userAgent, now()->toDateString()),
                'created_at' => now(),
            ]);
        } catch (\Exception $e) {
            // Silently fail - don't break downloads if analytics fails
            report($e);
        }

        return Storage::disk('local')->download(
            $translation->file_path,
            'translations.json'
        );
    }

    /**
     * Check if a UUID exists and return translation info for auto-fill
     *
     * Returns:
     * - type: 'update' (user has a translation with this UUID) or 'fork' (new branch)
     * - translation_id: user's own translation ID (for merge preview)
     * - main_translation_id: main owner's translation ID (for comparison)
     */
    public function checkUuid(Request $request)
    {
        $uuid = $request->get('uuid');

        if (!$uuid) {
            return response()->json(['exists' => false]);
        }

        // Find the main translation with this UUID (first uploaded)
        $mainTranslation = Translation::with(['game', 'user'])
            ->where('file_uuid', $uuid)
            ->orderBy('created_at', 'asc')
            ->first();

        if (!$mainTranslation) {
            return response()->json(['exists' => false]);
        }

        $userId = auth()->id();

        // Check if user has ANY translation with this UUID (main or branch)
        $userTranslation = Translation::where('file_uuid', $uuid)
            ->where('user_id', $userId)
            ->first();

        // type = 'update' if user has a translation, 'fork' if new contribution
        $type = $userTranslation ? 'update' : 'fork';

        return response()->json([
            'exists' => true,
            'type' => $type,
            'original_id' => $mainTranslation->id,
            'translation_id' => $userTranslation?->id, // User's own translation (for merge)
            'main_translation_id' => $mainTranslation->id, // Main for comparison
            'is_main_owner' => $userTranslation && $userTranslation->id === $mainTranslation->id,
            'game' => [
                'id' => $mainTranslation->game->id,
                'name' => $mainTranslation->game->name,
                'image_url' => $mainTranslation->game->image_url,
                'igdb_id' => $mainTranslation->game->igdb_id,
                'rawg_id' => $mainTranslation->game->rawg_id,
            ],
            'source_language' => $mainTranslation->source_language,
            'target_language' => $mainTranslation->target_language,
            'uploader' => $mainTranslation->user->name,

            // What this account's own row already says, so the form can show the decision back
            // rather than presenting an unticked box as if nothing had been decided.
            'accepts_branches' => (bool) $userTranslation?->accepts_branches,
        ]);
    }

    public function myTranslations(Request $request)
    {
        // Sorting, same vocabulary as the games list — with one option that only makes sense on
        // your own files: what is left to read. That is the list an author actually works from.
        //
        // Default is "recently worked on" rather than "recently uploaded": the reason to open
        // this page is to carry on, and the file you touched last is the one you carry on with.
        // It reads content_updated_at, never updated_at — a vote or a download on someone's
        // translation must not float it back to the top as if its author had just worked on it.
        $sort = $request->input('sort', 'updated');

        $query = auth()->user()->translations()->with(['game', 'forks']);

        match ($sort) {
            'new' => $query->orderByDesc('created_at'),
            'downloads' => $query->orderByDesc('download_count'),
            'review' => $query->orderByDesc('ai_count'),
            'game' => $query->orderBy(
                Game::select('name')->whereColumn('games.id', 'translations.game_id')
            ),
            default => $query->orderByRaw('COALESCE(content_updated_at, updated_at) DESC'),
        };

        // Two files differing only by language must not swap places between page loads, and
        // sorting by game puts a game's languages in an order anyone can predict.
        $translations = $query->orderBy('target_language')->orderByDesc('id')->get();

        // Load unreviewed branch counts for Main translations (single query)
        // A branch needs merging if: never reviewed OR modified since last review
        $branchCounts = [];
        $mainUuids = $translations->filter(fn($t) => $t->isMain())->pluck('file_uuid')->unique();
        if ($mainUuids->isNotEmpty()) {
            $branchCounts = Translation::whereIn('file_uuid', $mainUuids)
                ->where('visibility', 'branch')
                ->where(function ($q) {
                    $q->whereNull('reviewed_hash')
                      ->orWhereColumn('file_hash', '!=', 'reviewed_hash');
                })
                ->selectRaw('file_uuid, COUNT(*) as count')
                ->groupBy('file_uuid')
                ->pluck('count', 'file_uuid')
                ->toArray();
        }

        // How far the furthest translation of each listed game reaches, asked once for the whole
        // page: the coverage badge needs it, and the model would otherwise run its own MAX per
        // card.
        $gameMaxes = Translation::maxResolvedLinesByGame($translations->pluck('game_id'));

        // The two things this page must say out loud, computed here so the view asks nothing of
        // the database. Both are addressed to the author and appear NOWHERE public: a translation
        // that helps nobody is their problem to fix, not something to hold up in front of others.
        $emptyPublished = $translations->filter(
            fn ($t) => $t->visibility === 'public' && $t->isCaptureOnly()
        );

        // A branch whose Main has gone quiet. Two levels, and the first says nothing about
        // forking — the point is to inform, not to push anyone into leaving.
        $stalledBranches = $translations->filter(
            fn ($t) => $t->isBranch() && $t->mainIsDormant()
        );

        // Two more states a contributor could not see, kept apart on purpose because the answer
        // differs. A DELISTED Main is still there and can still take the work in — its author
        // has simply published nothing translated for thirty days, so the branch hangs from
        // something no player can find. An ORPHANED branch has no Main at all: nobody can merge
        // it, ever, and its only way forward is to become a translation of its own.
        $delistedMains = $translations->filter(fn ($t) => $t->mainIsDelisted());
        $orphanBranches = $translations->filter(fn ($t) => $t->isOrphanBranch());

        // A Main that came back, worked, and left the contributions aside. Distinct from silence:
        // dormancy counts days, this counts missed occasions.
        $ignoredBranches = $translations->filter(fn ($t) => $t->mainIgnoresContributions());

        return view('translations.mine', compact(
            'translations', 'branchCounts', 'sort', 'gameMaxes', 'emptyPublished', 'stalledBranches',
            'delistedMains', 'orphanBranches', 'ignoredBranches'
        ));
    }

    /**
     * The words about a translation: its description, the link to what it needs, and — on a
     * translation of one's own — whether its author calls it finished.
     *
     * 🔴 **A contributor edits their contribution.** This used to demand isMain(), so a branch
     * author was refused their own row: no description, no link to the fonts their contribution
     * needs. The form itself has been written for them from the start — it carries a whole
     * branch case showing the inherited status, locked, which nobody could ever reach.
     *
     * Same reasoning as the pen on the card beside it: correcting one's own lines is not a Main
     * privilege, and neither is saying what they are. Only what belongs to somebody else stays
     * out of reach — a branch never writes on the Main it contributes to.
     */
    public function edit(Translation $translation)
    {
        $user = auth()->user();
        $isAdmin = $user->isAdmin();

        if (!$isAdmin && $translation->user_id !== $user->id) {
            abort(403);
        }

        $translation->load(['game', 'user']);

        // Detect if accessed via admin route (for back button navigation)
        $fromAdmin = request()->routeIs('admin.*');

        return view('translations.edit', compact('translation', 'fromAdmin'));
    }

    public function update(Request $request, Translation $translation)
    {
        $user = auth()->user();
        $isAdmin = $user->isAdmin();

        if (!$isAdmin && $translation->user_id !== $user->id) {
            abort(403);
        }

        // ⚠ A contribution inherits whether it is finished, so the form does not offer the
        // control and nothing here may write it. Required on anything else: the radio pair is
        // always rendered there, and a missing value would silently mean "in progress".
        $isBranch = $translation->visibility === 'branch';

        // ⚠ Frozen means frozen, details included: the Main it contributes to no longer takes
        // contributions, so describing it better would be describing something that can never
        // move again. The admin screens keep their way in — moderation is not editing.
        if (!$isAdmin && $translation->isFrozenBranch()) {
            return back()->withErrors(['notes' =>
                'The translation you contribute to no longer accepts contributions. '
                . 'Turn this into your own version to carry on.']);
        }

        $request->validate([
            'status' => $isBranch ? 'prohibited' : 'required|in:in_progress,complete',
            'notes' => 'nullable|string|max:1000',
            'resources_url' => 'nullable|string|max:2048|url',
            'accepts_branches' => $isBranch ? 'prohibited' : 'nullable|boolean',
        ]);

        $changes = [
            'notes' => $request->notes,
            'resources_url' => $request->resources_url,
        ];

        if (!$isBranch) {
            $changes['status'] = $request->status;

            // An unticked checkbox sends nothing at all, so absence IS the answer here — reading
            // it as "leave unchanged" would make the box impossible to turn off.
            $changes['accepts_branches'] = $request->boolean('accepts_branches');
        }

        // ⚠ Read BEFORE the write, and compared: the notice belongs to the TRANSITION. Sending
        // it on every save would turn one decision into a stream of notices for people who have
        // already been told.
        $wasOpen = (bool) $translation->accepts_branches;

        $translation->update($changes);

        if ($wasOpen && !$translation->fresh()->accepts_branches) {
            $translation->notifyBranchesOfClosure();
        }

        // Redirect based on access route, not user role
        if (request()->routeIs('admin.*')) {
            return redirect()->route('admin.translations.show', $translation)
                ->with('success', __('my_translations.updated'));
        }

        return redirect()->route('translations.mine')
            ->with('success', __('my_translations.updated'));
    }

    public function destroy(Translation $translation)
    {
        $user = auth()->user();

        // Allow owner or admin
        if ($translation->user_id !== $user->id && !$user->isAdmin()) {
            abort(403);
        }

        // Delete file
        Storage::disk('local')->delete($translation->file_path);

        // Delete translation (forks will have parent_id set to null via onDelete)
        $translation->delete();

        return redirect()->route('translations.mine')
            ->with('success', 'Translation deleted successfully!');
    }

    /**
     * Show dashboard for a translation (Main or Branch view).
     * Main: sees branches stats, lines to merge
     * Branch: sees Main info, comparison, convert to fork option
     */
    public function dashboard(Translation $translation, TranslationService $service)
    {
        $user = auth()->user();

        // Verify user owns this translation
        if ($translation->user_id !== $user->id) {
            abort(403);
        }

        $translation->load(['game', 'user']);

        $isMain = $translation->visibility === 'public';

        if ($isMain) {
            // Main view: show branches and merge stats
            $branches = Translation::where('file_uuid', $translation->file_uuid)
                ->where('visibility', 'branch')
                ->with('user:id,name')
                ->orderBy('updated_at', 'desc')
                ->get();

            // Load Main content for comparison
            $mainContent = $this->getTranslationContent($translation);

            // Calculate diff stats for each branch
            $branchStats = [];
            foreach ($branches as $branch) {
                $branchContent = $this->getTranslationContent($branch);
                $stats = $this->calculateDiffStats($mainContent, $branchContent);
                $branchStats[$branch->id] = $stats;
            }

            // Total lines to merge (union of all branch differences)
            $totalLinesToMerge = 0;
            foreach ($branchStats as $stats) {
                $totalLinesToMerge += $stats['different'] + $stats['branch_only'];
            }

            return view('translations.dashboard', compact(
                'translation',
                'isMain',
                'branches',
                'branchStats',
                'totalLinesToMerge'
            ));
        } else {
            // Branch view: show Main info and comparison
            $mainTranslation = Translation::where('file_uuid', $translation->file_uuid)
                ->where('visibility', 'public')
                ->with(['user:id,name', 'game'])
                ->first();

            $diffStats = null;
            if ($mainTranslation) {
                $mainContent = $this->getTranslationContent($mainTranslation);
                $branchContent = $this->getTranslationContent($translation);
                $diffStats = $this->calculateDiffStats($mainContent, $branchContent);
            }

            return view('translations.dashboard', compact(
                'translation',
                'isMain',
                'mainTranslation',
                'diffStats'
            ));
        }
    }

    /**
     * Leave a lineage: the branch stays where it is, and an independent translation is CREATED
     * from what it holds. The author downloads the new file and puts it in their game.
     *
     * 🔴 **It used to rewrite the branch in place** — new uuid, visibility public, one row — so the
     * contribution simply stopped existing, along with everything attached to it. The mod does the
     * opposite and always has: it changes the local uuid and uploads, which creates a row and
     * leaves the branch untouched. The same act had two different outcomes depending on where it
     * was taken, which an ecosystem cannot afford.
     *
     * ⚠ **Creating is the safer of the two, and that is why it wins.** Removing the branch is then
     * a separate, deliberate act — and keeping both is a legitimate choice: carrying on
     * contributing to the Main while running one's own version is not a contradiction. What the
     * old behaviour destroyed, nobody had asked to destroy: the branch's contribution counters and
     * its author's name stay with the row, and stay for as long as the account exists.
     *
     * ⚠ **Once.** A branch may be left once; a second promotion would file a second identical
     * translation under the same name, which is the loop the upload endpoint refuses too.
     */
    public function convertToFork(Translation $translation, TranslationService $service)
    {
        $user = auth()->user();

        // Verify user owns this translation
        if ($translation->user_id !== $user->id) {
            abort(403);
        }

        // Must be a branch to convert
        if ($translation->visibility !== 'branch') {
            return back()->withErrors(['error' => __('dashboard.not_a_branch')]);
        }

        // Generate new UUID
        $newUuid = \Illuminate\Support\Str::uuid()->toString();

        // Load and update file content
        $path = $translation->getSafeFilePath();
        if (!$path || !file_exists($path)) {
            return back()->withErrors(['error' => __('dashboard.file_not_found')]);
        }

        $rawContent = file_get_contents($path);
        $rawContent = $service->normalizeContent($rawContent);
        $content = json_decode($rawContent, true);

        if (!is_array($content)) {
            return back()->withErrors(['error' => __('dashboard.invalid_file')]);
        }

        // A copy carrying the new identity. The branch's own file is not touched: it is still the
        // file its row describes, and its hashes still match it.
        $content['_uuid'] = $newUuid;

        $jsonFlags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $forkPath = $service->storeFile(json_encode($content, $jsonFlags), $newUuid);

        // ⚠ Already left once. Told apart by the content, like the upload endpoint: a second
        // promotion of the same branch would file a second identical translation under one name.
        $alreadyLeft = Translation::where('user_id', $user->id)
            ->where('visibility', 'public')
            ->where('content_hash', $service->computeContentHash($content))
            ->exists();

        if ($alreadyLeft) {
            $service->deleteFile($forkPath);

            return back()->withErrors(['error' => __('dashboard.already_forked')]);
        }

        // Who started this, and how much of it was already written when we took it.
        //
        // The snapshot can only be taken here: the original keeps growing afterwards, so asking
        // the question later would answer a different one. And it is written rather than
        // derived, because parent_id is "on delete set null" — the credit has to outlive the
        // row it points at.
        $main = $translation->getMain();

        $fork = Translation::create([
            'game_id' => $translation->game_id,
            'user_id' => $user->id,
            // 🔴 No parent_id: a fork LEFT the lineage. parent_id is what makes a row a
            // contribution to another, and the credit it used to stand in for is written out in
            // the origin_* columns below, which survive the row they name.
            'parent_id' => null,
            'origin_translation_id' => $main?->id ?? $translation->parent_id,
            'origin_user_id' => $main?->user_id ?? $translation->parent?->user_id,
            'origin_resolved_lines' => $main?->resolved_lines,
            'origin_file_hash' => $main?->file_hash,
            'source_language' => $translation->source_language,
            'target_language' => $translation->target_language,
            'line_count' => $translation->line_count,
            'human_count' => $translation->human_count,
            'validated_count' => $translation->validated_count,
            'ai_count' => $translation->ai_count,
            'capture_count' => $translation->capture_count,
            'skipped_count' => $translation->skipped_count,
            'status' => $translation->status,
            'visibility' => 'public',
            'notes' => $translation->notes,
            'resources_url' => $translation->resources_url,
            // ⚠ Closed, like every new Main. Taking in contributions is work nobody agreed to by
            // leaving a lineage — and the branch this comes from could not answer the question.
            'accepts_branches' => false,
            'file_path' => $forkPath,
            'file_uuid' => $newUuid,
            'font_config' => $translation->font_config,
            'settings_summary' => $translation->settings_summary,
        ]);

        // Both hashes, from the file that was just written.
        $fork->file_hash = $fork->computeHash();
        $fork->content_hash = $fork->computeContentHash();
        $fork->save();

        // Return the file for download
        return Storage::disk('local')->download(
            $fork->file_path,
            'translations.json',
            ['Content-Type' => 'application/json']
        );
    }

    /**
     * Calculate diff stats between main and branch content.
     */
    private function calculateDiffStats(?array $mainContent, ?array $branchContent): array
    {
        if (!$mainContent || !$branchContent) {
            return ['same' => 0, 'different' => 0, 'main_only' => 0, 'branch_only' => 0];
        }

        // Filter out metadata keys
        $mainKeys = array_filter(array_keys($mainContent), fn($k) => !str_starts_with($k, '_'));
        $branchKeys = array_filter(array_keys($branchContent), fn($k) => !str_starts_with($k, '_'));

        $allKeys = array_unique(array_merge($mainKeys, $branchKeys));

        $same = 0;
        $different = 0;
        $mainOnly = 0;
        $branchOnly = 0;

        foreach ($allKeys as $key) {
            $inMain = in_array($key, $mainKeys);
            $inBranch = in_array($key, $branchKeys);

            if ($inMain && $inBranch) {
                $mainValue = $this->extractValue($mainContent[$key]);
                $branchValue = $this->extractValue($branchContent[$key]);

                if ($mainValue === $branchValue) {
                    $same++;
                } else {
                    $different++;
                }
            } elseif ($inMain) {
                $mainOnly++;
            } else {
                $branchOnly++;
            }
        }

        return [
            'same' => $same,
            'different' => $different,
            'main_only' => $mainOnly,
            'branch_only' => $branchOnly,
        ];
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
     * Show merge preview for comparing local file with online version.
     * User must own the translation.
     *
     * Supports two access modes:
     * 1. Web upload flow: local content passed via sessionStorage (JS)
     * 2. Mod flow: local content passed via ?token=xxx (from API init)
     *
     * The page itself carries no translation data: the JS fetches it from
     * mergePreviewData(), and the mod's local content lives in a file on the
     * private disk referenced by the token. Neither the session nor the page
     * ever holds the content — large files broke both on shared hosting.
     */
    public function mergePreview(Request $request, Translation $translation)
    {
        $hasTokenContent = false;
        $tokenError = null;
        $token = $request->query('token');

        // Mode 1: Token-based auth (from mod)
        // Token provides authentication - no web session required
        if ($token) {
            $mergeToken = MergePreviewToken::findValid($token);

            if (!$mergeToken) {
                abort(403, 'Invalid or expired token. Please try again from the mod.');
            }

            if ((int) $mergeToken->translation_id !== (int) $translation->id) {
                abort(403, 'Token does not match this translation.');
            }

            // Ownership is required to PUBLISH. A comparison whose result goes back to the mod
            // writes nothing here, so it only needs the translation to be readable — which is
            // how a branch gets to compare itself with its Main. Re-checked rather than trusted
            // from the init call: this is the request that opens the file.
            if ($mergeToken->isLocalDestination()) {
                if (!$translation->isReadableBy($mergeToken->user)) {
                    abort(403, 'This translation is not available for comparison.');
                }
            } elseif ((int) $mergeToken->user_id !== (int) $translation->user_id) {
                abort(403, 'You can only preview your own translations.');
            }

            // Check if user was already authenticated before token login
            $wasAlreadyLoggedIn = auth()->check() && (int) auth()->id() === (int) $mergeToken->user_id;

            // Create a web session (needed for CSRF + POST save)
            if (!$wasAlreadyLoggedIn) {
                Auth::loginUsingId($mergeToken->user_id);
            }
            session([
                // Only mark as scoped if user wasn't already logged in
                // This prevents destroying their existing web session after save
                'merge_preview_only' => !$wasAlreadyLoggedIn,
                'merge_preview_translation_id' => $translation->id,
                'merge_preview_token' => $mergeToken->token,
            ]);

            // One-time login: the token can no longer authenticate, but the
            // row and content file survive so the post-redirect request and
            // the save's SSE publish can still find them.
            $mergeToken->markConsumed();

            // Redirect to the same URL without the token: it must not linger in
            // browser history, server/proxy logs or Referer headers. The next
            // request is served by session recovery (Mode 2) below.
            return redirect()->route('translations.merge-preview', $translation, 303);
        }
        // Mode 2: Session recovery (after token-consuming redirect or page reload).
        // The token reference was bound to this translation by Mode 1 after
        // ownership checks; the content itself stays in the token's file.
        elseif (session('merge_preview_token') && (int) session('merge_preview_translation_id') === (int) $translation->id) {
            $mergeToken = MergePreviewToken::findForSession(session('merge_preview_token'), $translation->id);

            if ($mergeToken && $mergeToken->getContentFilePath()) {
                $hasTokenContent = true;
            } else {
                // Token expired or content file gone: fail loudly, never
                // silently degrade to Mode 3 (which would show a misleading
                // "local file not found" error).
                Log::warning('[MergePreview] Session token expired or content file missing', [
                    'translation_id' => $translation->id,
                    'token_found' => $mergeToken !== null,
                ]);

                $scopedSession = (bool) session('merge_preview_only');
                session()->forget(['merge_preview_only', 'merge_preview_translation_id', 'merge_preview_token']);

                if ($scopedSession) {
                    // Scoped sessions exist only for the merge: close it entirely
                    Auth::logout();
                    session()->invalidate();
                    session()->regenerateToken();

                    return redirect()
                        ->route('home')
                        ->with('error', __('merge_preview.error_session_expired'));
                }

                $tokenError = __('merge_preview.error_session_expired');
            }
        }
        // Mode 3: Web session auth (from website)
        else {
            $user = auth()->user();

            if (!$user) {
                return redirect()->route('login')->with('error', 'Please log in to access merge preview.');
            }

            if ((int) $translation->user_id !== (int) $user->id) {
                abort(403, 'You can only merge your own translations.');
            }
        }

        // Load game and user relationships
        $translation->load(['game', 'user']);

        // The online file must exist for the data endpoint to serve it
        $onlinePath = $translation->getSafeFilePath();
        if (!$onlinePath || !file_exists($onlinePath)) {
            abort(404, 'Translation file not found.');
        }

        $session = session('merge_preview_token')
            && (int) session('merge_preview_translation_id') === (int) $translation->id
                ? MergePreviewToken::findForSession(session('merge_preview_token'), $translation->id)
                : null;

        // Which way this comparison runs. It decides far more than a form action: what counts
        // as a change to send is reversed, and Delete stops meaning "remove it from the server"
        // to mean "remove it from my file".
        $toLocal = $session?->isLocalDestination() ?? false;

        return view('translations.merge-preview', compact(
            'translation',
            'hasTokenContent',
            'tokenError',
            'toLocal'
        ));
    }

    /**
     * Stream the merge preview data (local + online content) as JSON.
     *
     * Called by the merge-preview page's JS. Contents are streamed straight
     * from the files with constant memory — no PHP-side decoding, no size
     * limit below the upload cap. The JS already filters metadata keys and
     * normalizes line endings on both sides.
     *
     * "local" is the mod-flow content file (null in the web upload flow,
     * where the local file lives in the browser's sessionStorage).
     */
    /**
     * Is what this page shows still what the server holds?
     *
     * 🔴 **Asked once, when the tab comes back into view — never on a timer.** A comparison can sit
     * open for hours; meanwhile the online version may be rewritten (another tab, another device),
     * and the session that authorises writing it back expires on its own (15 minutes, 2 hours once
     * opened). Both were only discovered by pressing Save, after the work was done.
     *
     * ⚠ Deliberately tiny: an empty envelope and a hash, no content. The heavy endpoint next door
     * streams megabytes, which is exactly what must not happen every time somebody alt-tabs.
     *
     * ⚠ **Authorisation is the SAME call the data endpoint makes**, not a second rule that looks
     * like it — and the 410 it raises on a dead session is what tells the page to stop offering to
     * save. `session` says which flow this is, because a web-flow page has no session to lose.
     */
    public function mergePreviewState(Translation $translation): \Illuminate\Http\JsonResponse
    {
        $this->resolveMergePreviewPaths($translation);

        $live = session('merge_preview_token')
            && (int) session('merge_preview_translation_id') === (int) $translation->id;

        return response()->json([
            'file_hash' => $translation->file_hash,
            'session' => $live ? 'mod' : 'web',
        ], 200, ['Cache-Control' => 'no-store, private']);
    }

    /**
     * Who may read the two sides of a comparison, and where they are.
     *
     * Extracted so every endpoint serving comparison data enforces the SAME rule. Two copies of
     * an access check drift, and the one that drifts is the one nobody re-reads.
     *
     * @return array{0: ?string, 1: string} [local file path or null, online file path]
     */
    private function resolveMergePreviewPaths(Translation $translation): array
    {
        $localPath = null;

        // Mod flow: ongoing token session (bound by mergePreview Mode 1 after
        // ownership checks — the token only ever references its own translation)
        if (session('merge_preview_token') && (int) session('merge_preview_translation_id') === (int) $translation->id) {
            $mergeToken = MergePreviewToken::findForSession(session('merge_preview_token'), $translation->id);
            $localPath = $mergeToken?->getContentFilePath();

            if (!$localPath) {
                abort(410, 'Merge preview session expired. Please restart the comparison from the mod.');
            }
        }
        // Web flow: regular authenticated owner
        else {
            $user = auth()->user();

            if (!$user) {
                abort(401, 'Authentication required.');
            }

            if ((int) $translation->user_id !== (int) $user->id) {
                abort(403, 'You can only merge your own translations.');
            }
        }

        $onlinePath = $translation->getSafeFilePath();
        if (!$onlinePath || !file_exists($onlinePath)) {
            abort(404, 'Translation file not found.');
        }

        return [$localPath, $onlinePath];
    }

    /**
     * The file settings of both sides, as rows that can be compared one by one.
     *
     * Served apart from the lines rather than inside them: mergePreviewData streams both files
     * without ever decoding them, which is what keeps a large translation cheap to load. Settings
     * are a handful of rows, so they are extracted here — once, in PHP — instead of being
     * re-derived in JavaScript, which would leave two definitions of "what a setting is" to keep
     * in step with the mod.
     */
    public function mergePreviewSettings(Request $request, Translation $translation, TranslationService $service)
    {
        [$localPath, $onlinePath] = $this->resolveMergePreviewPaths($translation);

        return response()->json([
            'local' => $localPath ? $this->comparableSettingsOf($localPath, $service) : null,
            'online' => $this->comparableSettingsOf($onlinePath, $service),
        ])->header('Cache-Control', 'no-store, private');
    }

    /**
     * Comparable settings of one file, or an empty set when it cannot be read. Unreadable is not
     * an error here: the lines are the subject of the page, and failing the whole comparison
     * because a settings block is malformed would be a poor trade.
     */
    private function comparableSettingsOf(string $path, TranslationService $service): array
    {
        $json = json_decode(file_get_contents($path), true);

        return is_array($json) ? $service->extractComparableSettings($json) : [];
    }

    /**
     * The local side of an ongoing comparison, decoded, or null when it is no longer available.
     *
     * It only exists for the mod flow, where the file was uploaded with the token — and it
     * expires. The web flow keeps its file in the browser, which is why the settings comparison
     * is not offered there in the first place.
     */
    private function localMergeContent(Translation $translation, TranslationService $service): ?array
    {
        if (!session('merge_preview_token')
            || (int) session('merge_preview_translation_id') !== (int) $translation->id) {
            return null;
        }

        $path = MergePreviewToken::findForSession(session('merge_preview_token'), $translation->id)
            ?->getContentFilePath();
        if (!$path || !file_exists($path)) {
            return null;
        }

        $json = json_decode($service->normalizeContent(file_get_contents($path)), true);

        return is_array($json) ? $json : null;
    }

    public function mergePreviewData(Request $request, Translation $translation)
    {
        [$localPath, $onlinePath] = $this->resolveMergePreviewPaths($translation);

        return response()->stream(function () use ($localPath, $onlinePath) {
            echo '{"local":';
            if ($localPath) {
                readfile($localPath);
            } else {
                echo 'null';
            }
            echo ',"online":';
            readfile($onlinePath);
            echo '}';
        }, 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * Get translation content from file, excluding metadata keys.
     */
    private function getTranslationContent(Translation $translation): ?array
    {
        $path = $translation->getSafeFilePath();
        if (!$path || !file_exists($path)) {
            return null;
        }

        $rawContent = file_get_contents($path);
        $content = json_decode($rawContent, true);
        if (!is_array($content)) {
            return null;
        }

        return $content;
    }

    /**
     * Apply merge preview selections to the user's translation.
     * Same rules as MergeController::apply for tag handling.
     */
    /**
     * Send the arbitrated result back to the mod, WITHOUT publishing anything.
     *
     * A comparison could previously only end by writing the server file, so reviewing changes
     * without publishing them was impossible — and so was comparing against a translation one
     * does not own, since publishing there is forbidden and the comparison was refused upfront.
     * That is the case this route exists for: a branch measuring itself against its Main.
     *
     * Deliberately a route of its own rather than a flag on applyMergePreview. A flag is a thing
     * one forgets to check; two routes cannot be confused, and each one refuses a token that
     * does not belong to it.
     */
    public function applyMergePreviewLocally(Request $request, Translation $translation, TranslationService $service)
    {
        $token = session('merge_preview_token')
            && (int) session('merge_preview_translation_id') === (int) $translation->id
                ? MergePreviewToken::findForSession(session('merge_preview_token'), $translation->id)
                : null;

        if (!$token || !$token->getContentFilePath()) {
            return back()->withErrors(['error' => __('merge_preview.error_session_expired')]);
        }

        if (!$token->isLocalDestination()) {
            // This token was opened to publish. Letting it end here instead would quietly do
            // something other than what the mod asked for.
            abort(403, 'This comparison was not opened to return its result to the mod.');
        }

        $request->validate([
            'selections' => 'sometimes|array',
            'selections.*.key' => 'required|string',
            'selections.*.value' => 'present|string',
            'selections.*.tag' => 'required|in:H,A,V,M,S',
            'selections.*.source' => 'required|string',
            // Whether the screen answered this row on its own — see resolveMergedTag's $claimed.
            // Optional: a client that predates it only ever sent rows somebody had picked.
            'selections.*.auto' => 'sometimes',
            'deletions' => 'sometimes|array',
            'deletions.*' => 'string',
            'settings' => 'sometimes|array',
            'settings.*' => 'in:local,online',
        ]);

        $localPath = $token->getContentFilePath();
        $local = json_decode($service->normalizeContent(file_get_contents($localPath)), true);
        if (!is_array($local)) {
            return back()->withErrors(['error' => __('merge_preview.error_invalid_json')]);
        }

        $onlinePath = $translation->getSafeFilePath();
        $online = $onlinePath && file_exists($onlinePath)
            ? json_decode($service->normalizeContent(file_get_contents($onlinePath)), true)
            : null;
        if (!is_array($online)) {
            return back()->withErrors(['error' => __('merge_preview.error_file_not_found')]);
        }

        // The result starts from what the player HAS, not from the server file: this is their
        // own file coming back to them, and everything they never arbitrated must survive
        // untouched — including the metadata that carries its lineage.
        $result = $local;

        foreach ($request->input('selections', []) as $sel) {
            $key = $service->normalizeContent($sel['key']);
            // Metadata keys are never written through selections — same guard as the edit
            // session: a forged {v,t} object there would corrupt the file's lineage
            if (str_starts_with($key, '_')) {
                continue;
            }

            // ⚠ `auto` marks a row the screen answered on its own: kept as it is, tag untouched.
            // Without it, a machine line the comparison brought down landed in the player's own
            // file marked human-checked, and their quality bar rose with nobody having read a word.
            // Absent on an older client, which only ever sent rows somebody had picked.
            $tag = TranslationService::resolveMergedTag(
                $sel['tag'], $sel['source'], claimed: !filter_var($sel['auto'] ?? false, FILTER_VALIDATE_BOOL));

            $result[$key] = TranslationService::rebuildEntry(
                $result[$key] ?? null,
                $service->normalizeContent($sel['value']),
                $tag
            );
        }

        foreach ($request->input('deletions', []) as $delKey) {
            $delKey = $service->normalizeContent($delKey);
            if (!str_starts_with($delKey, '_') && array_key_exists($delKey, $result)) {
                unset($result[$delKey]);
            }
        }

        // Settings choices are relative to the online side here: "online" means take theirs
        $settingChoices = [];
        foreach ($request->input('settings', []) as $key => $side) {
            // Reversed on purpose: applySettingSelections copies from its second argument when
            // told 'local', and the side being pulled in here is the online one
            $settingChoices[$key] = $side === 'online' ? 'local' : 'online';
        }
        if (!empty($settingChoices)) {
            $result = $service->applySettingSelections($result, $online, $settingChoices);
        }

        // Hand it to the mod through the file it already knows how to fetch
        $token->replaceContent($result);

        SsePublisher::mergeCompleted($token->token, [
            'translation_id' => $translation->id,
            'destination' => MergePreviewToken::DESTINATION_LOCAL,
            'line_count' => count(array_filter(
                array_keys($result),
                fn ($k) => !str_starts_with($k, '_')
            )),
        ]);

        return $this->finishMergePreviewSession(__('merge_preview.local_apply_success'));
    }

    public function applyMergePreview(Request $request, Translation $translation, TranslationService $service)
    {
        $user = auth()->user();

        // Verify user owns this translation
        if ((int) $translation->user_id !== (int) $user->id) {
            abort(403, 'You can only modify your own translations.');
        }

        // A token opened to return its result to the mod must not end up publishing instead
        if (session('merge_preview_token')
            && (int) session('merge_preview_translation_id') === (int) $translation->id) {
            $sessionToken = MergePreviewToken::findForSession(session('merge_preview_token'), $translation->id);
            if ($sessionToken && $sessionToken->isLocalDestination()) {
                abort(403, 'This comparison was opened to return its result to the mod.');
            }
        }

        // Validate selections and deletions
        $request->validate([
            'selections' => 'sometimes|array',
            'selections.*.key' => 'required|string',
            'selections.*.value' => 'present|string',
            'selections.*.tag' => 'required|in:H,A,V,M,S',
            'selections.*.source' => 'required|string', // 'local', 'online', or 'manual'
            // Whether the screen answered this row on its own — see resolveMergedTag's $claimed.
            // Optional: a client that predates it only ever sent rows somebody had picked.
            'selections.*.auto' => 'sometimes',
            'deletions' => 'sometimes|array',
            'deletions.*' => 'string',
            // Only a side is accepted per setting: the entry itself is copied server-side from
            // the file it comes from, so nothing the browser sends decides what gets written
            'settings' => 'sometimes|array',
            'settings.*' => 'in:local,online',
        ]);

        if (empty($request->input('selections'))
            && empty($request->input('deletions'))
            && empty($request->input('settings'))) {
            return back()->withErrors(['error' => 'No changes to apply.']);
        }

        // Load current file content
        $path = $translation->getSafeFilePath();
        if (!$path || !file_exists($path)) {
            return back()->withErrors(['error' => __('merge_preview.error_file_not_found')]);
        }

        $rawContent = file_get_contents($path);
        $rawContent = $service->normalizeContent($rawContent);
        $content = json_decode($rawContent, true);
        if (!is_array($content)) {
            return back()->withErrors(['error' => __('merge_preview.error_invalid_json')]);
        }

        // Apply selections
        $modifiedCount = 0;
        foreach ($request->input('selections', []) as $sel) {
            $key = $service->normalizeContent($sel['key']);
            $value = $service->normalizeContent($sel['value']);
            $tag = $sel['tag'];
            $source = $sel['source'];

            // Shared rule — see TranslationService::resolveMergedTag. `auto` marks a row the screen
            // answered on its own, which may not claim a reading.
            $tag = TranslationService::resolveMergedTag(
                $tag, $source, claimed: !filter_var($sel['auto'] ?? false, FILTER_VALIDATE_BOOL));

            // rebuildEntry keeps the ordering index "i" of the existing entry
            $content[$key] = TranslationService::rebuildEntry($content[$key] ?? null, $value, $tag);
            $modifiedCount++;
        }

        // Apply deletions — metadata keys are untouchable (same rule as MergeController)
        $deletedCount = 0;
        foreach ($request->input('deletions', []) as $delKey) {
            $delKey = $service->normalizeContent($delKey);
            if (!str_starts_with($delKey, '_') && array_key_exists($delKey, $content)) {
                unset($content[$delKey]);
                $deletedCount++;
            }
        }

        // Apply settings choices. Each winning entry is COPIED from the file it belongs to —
        // the browser only says which side wins.
        $settingChoices = $request->input('settings', []);
        if (!empty($settingChoices)) {
            $localContent = $this->localMergeContent($translation, $service);
            if ($localContent === null) {
                // The local file lives in the token's storage and expires. Saying so is the only
                // honest answer: applying half the choices would leave a file nobody asked for.
                return back()->withErrors(['error' => __('merge_preview.error_session_expired')]);
            }

            $content = $service->applySettingSelections($content, $localContent, $settingChoices);
        }

        // Save the file
        $jsonFlags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        file_put_contents($path, json_encode($content, $jsonFlags));

        // Recalculate counters and hash — both, see MergeController.
        $translation->file_hash = $translation->computeHash();
        $translation->content_hash = $translation->computeContentHash();
        $tagCounts = Translation::extractTagCounts($content);
        $translation->human_count = $tagCounts['human_count'];
        $translation->validated_count = $tagCounts['validated_count'];
        $translation->ai_count = $tagCounts['ai_count'];
        $translation->capture_count = $tagCounts['capture_count'];
        $translation->skipped_count = $tagCounts['skipped_count'];
        $translation->line_count = count(array_filter(
            array_keys($content),
            fn($k) => !str_starts_with($k, '_')
        ));
        $translation->save();

        // Signal SSE via Redis pub/sub — Node.js relays to connected mods
        $mergeTokens = MergePreviewToken::where('translation_id', $translation->id)->get();
        foreach ($mergeTokens as $mergeToken) {
            if (!$mergeToken->isExpired()) {
                SsePublisher::mergeCompleted($mergeToken->token, [
                    'translation_id' => $translation->id,
                    'file_hash' => $translation->file_hash,
                    'line_count' => $translation->line_count,
                ]);
            }
        }
        SsePublisher::translationUpdated($translation->id, [
            'file_hash' => $translation->file_hash,
            'line_count' => $translation->line_count,
            'vote_count' => $translation->vote_count,
            'updated_at' => $translation->updated_at->toIso8601String(),
        ]);

        // The merge is done: the tokens and their content files reference a
        // now-obsolete local state, drop them (after the SSE publish above)
        foreach ($mergeTokens as $mergeToken) {
            $mergeToken->deleteWithFile();
        }

        return $this->finishMergePreviewSession(
            trans_choice('merge_preview.save_success', $modifiedCount + $deletedCount, [
                'count' => $modifiedCount + $deletedCount,
            ])
        );
    }

    /**
     * Close a comparison and send the visitor somewhere sensible.
     *
     * A session opened by a mod token is scoped to that one comparison: it is logged out and
     * destroyed, so it can never serve for anything else. A regular web session merely loses its
     * reference to the token, so a stale one cannot be recovered by revisiting the URL.
     *
     * Shared by both apply routes — the one that publishes and the one that answers the mod —
     * because a session left half-closed is exactly the kind of thing one route forgets.
     */
    private function finishMergePreviewSession(string $message)
    {
        $scoped = (bool) session('merge_preview_only');
        session()->forget(['merge_preview_only', 'merge_preview_translation_id', 'merge_preview_token']);

        if ($scoped) {
            Auth::logout();
            session()->invalidate();
            session()->regenerateToken();

            return redirect()->route('home')->with('success', $message);
        }

        return redirect()->route('translations.mine')->with('success', $message);
    }

    /**
     * Find or create a game based on existing game_id or external API data
     */
    private function findOrCreateGame(Request $request): Game
    {
        // If we have a direct game_id (from UUID auto-detection), use it
        if ($request->filled('game_id')) {
            return Game::findOrFail($request->input('game_id'));
        }

        // Otherwise, we must have external API data
        $source = $request->input('game_source');
        $externalId = $request->input('game_external_id');
        $imageUrl = $request->input('game_image_url');
        $name = $request->input('game_name');

        $idField = $source === 'igdb' ? 'igdb_id' : 'rawg_id';

        // Try to find existing game by external ID
        $game = Game::where($idField, $externalId)->first();

        if ($game) {
            // Update image if we have a new one
            if ($imageUrl && !$game->image_url) {
                $game->update(['image_url' => $imageUrl]);
            }
            return $game;
        }

        // Create new game with external ID
        return Game::create([
            'name' => $name,
            $idField => $externalId,
            'image_url' => $imageUrl,
        ]);
    }
}
