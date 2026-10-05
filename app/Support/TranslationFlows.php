<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Translation;
use Illuminate\Http\Request;

/**
 * What happens to translations over their life, as the admin "Flows" screen reads it — and the one
 * place every such event is written from.
 *
 * 🔴 **Written into the existing journal (`audit_logs`), not into a table of its own** (2026-10-05).
 * Publication, deletion and moving a lineage to another game were already logged there; the screen
 * reads them, and the events that were missing (a merge saved, a fork made on the site, details
 * changed, a publication refused) join them. One journal, one retention rule: these rows are the
 * memory of the content and are kept, their IP address and user agent cleared at twelve months
 * (`audit:purge-ips`) — which is why the program that sent an event is written here as a word and
 * a version, never as the agent string.
 *
 * ⚠ **Every event carries the same context** (`context()`): the game, the two languages and where
 * it came from. The screen filters and counts on those keys whatever the event; an event written
 * without them is an event the game filter cannot find. Rows from before 2026-10-05 lack some of
 * them, and the screen shows "—" rather than guessing.
 */
class TranslationFlows
{
    public const PUBLISHED = AuditLog::ACTION_TRANSLATION_UPLOAD;
    public const DELETED = AuditLog::ACTION_TRANSLATION_DELETE;
    public const GAME_CHANGED = 'translation.game_changed';

    /** Lines written from the site's merge screens: a Main taking branches in, or a mod's file merged in the browser. */
    public const CONTENT_SAVED = 'translation.content_saved';

    /** A branch turned into a translation of its own from the site (an upload declaring a fork is a publication). */
    public const FORKED = 'translation.forked';

    /** Description, resources link, status or "accepts branches" changed. */
    public const DETAILS_CHANGED = 'translation.details_changed';

    /** A publication the site turned down — the reason, and what the client said about the game. */
    public const REFUSED = 'translation.upload_refused';

    /** Everything the screen reads, in the order its tiles show them. */
    public static function actions(): array
    {
        return array_values(self::SLUGS);
    }

    /** The word each event goes by in the screen's address (`?type=deleted`) — and the screen's order. */
    public const SLUGS = [
        'published' => self::PUBLISHED,
        'edited' => self::CONTENT_SAVED,
        'details' => self::DETAILS_CHANGED,
        'forked' => self::FORKED,
        'moved' => self::GAME_CHANGED,
        'deleted' => self::DELETED,
        'refused' => self::REFUSED,
    ];

    /** How the screen names each event, its icon and its colour (Tailwind and Chart.js). */
    public const LABELS = [
        self::PUBLISHED => ['label' => 'Published', 'icon' => 'fa-upload', 'class' => 'text-green-400', 'rgb' => '34, 197, 94'],
        self::CONTENT_SAVED => ['label' => 'Edited on the site', 'icon' => 'fa-pen', 'class' => 'text-blue-400', 'rgb' => '59, 130, 246'],
        self::DETAILS_CHANGED => ['label' => 'Details changed', 'icon' => 'fa-sliders', 'class' => 'text-cyan-400', 'rgb' => '6, 182, 212'],
        self::FORKED => ['label' => 'Forked', 'icon' => 'fa-code-fork', 'class' => 'text-purple-400', 'rgb' => '168, 85, 247'],
        self::GAME_CHANGED => ['label' => 'Moved to another game', 'icon' => 'fa-right-left', 'class' => 'text-yellow-400', 'rgb' => '234, 179, 8'],
        self::DELETED => ['label' => 'Deleted', 'icon' => 'fa-trash', 'class' => 'text-red-400', 'rgb' => '239, 68, 68'],
        self::REFUSED => ['label' => 'Refused', 'icon' => 'fa-ban', 'class' => 'text-orange-400', 'rgb' => '249, 115, 22'],
    ];

    /** Who deleted a translation (TranslationService::DELETED_BY_*), as the screen says it. */
    public const DELETED_BY = [
        'author' => 'by its author',
        'admin' => 'by an admin',
        'report' => 'after a report',
        'account_deleted' => 'with its author\'s account',
    ];

    /** Why a publication was refused — the `RefusedCode` values of the API contract. */
    public const REFUSALS = [
        'branches_refused' => 'Main takes no branches',
        'main_abandoned' => 'Main\'s owner deleted their account',
        'main_gone' => 'Main deleted, branches remain',
        'branch_frozen' => 'Branch frozen',
        'game_mismatch' => 'Game read contradicts the game picked',
        'game_not_found' => 'Game not identified',
        'game_changed' => 'Main moved to another game',
        'game_not_picked' => 'No game picked',
        'game_ambiguous' => 'Several games share the name',
        'store_unavailable' => 'Steam not answering — try again later',
    ];

    /** Where an event came from (`via`), as the screen says it. */
    public const VIA = [
        'mod' => 'UGT Mod',
        'manager' => 'UGT Manager',
        'site' => 'Website',
        'admin' => 'Admin',
        'system' => 'System',
    ];

    /** The field names of a details change, as the screen says them. */
    private const DETAIL_NAMES = [
        'notes' => 'description',
        'resources_url' => 'resources link',
        'status' => 'status',
        'accepts_branches' => 'accepts branches',
    ];

    /**
     * The fields whose change is a "details changed" event — what an author says ABOUT their
     * translation. The lines themselves are a publication or a saved merge.
     */
    public const DETAILS = ['notes', 'resources_url', 'status', 'accepts_branches'];

    /** Fields whose new value is worth keeping. Notes are not: a thousand characters of text per edit, for a fact ("changed") the screen can state without them. */
    private const DETAIL_VALUES = ['resources_url', 'status', 'accepts_branches'];

    /**
     * Where an act came from: `admin` (an admin screen), `mod` or `manager` (with their version),
     * `site` (a browser), or `system` (no request at all — a command or a job).
     *
     * ⚠ The admin is read from the ROUTE, not from the account: an admin publishing their own
     * translation from the ordinary form is a user like any other, and only the /admin screens
     * are moderation.
     */
    public static function via(?Request $request = null): array
    {
        $request ??= self::currentRequest();

        if ($request === null) {
            return ['via' => 'system'];
        }

        if (str_starts_with((string) $request->route()?->getName(), 'admin.')) {
            return ['via' => 'admin'];
        }

        $client = ClientAgent::ours($request->userAgent());
        if ($client !== null) {
            return array_filter([
                'via' => $client['kind'],
                'version' => $client['version'],
            ], fn ($v) => $v !== null);
        }

        return ['via' => 'site'];
    }

    /** The keys every flow event carries, read from the translation it is about. */
    public static function context(Translation $translation, ?Request $request = null): array
    {
        return [
            'game_id' => $translation->game_id,
            'game' => $translation->game?->name,
            'source_language' => $translation->source_language,
            'target_language' => $translation->target_language,
            'visibility' => $translation->visibility,
        ] + self::via($request);
    }

    /** One event about one translation, with its context. */
    public static function log(string $action, Translation $translation, array $details = [], ?Request $request = null, ?int $actorId = null): AuditLog
    {
        $request ??= self::currentRequest();

        return AuditLog::log(
            $action,
            $actorId ?? self::actorId($request),
            'Translation',
            $translation->id,
            $details + self::context($translation, $request),
            $request
        );
    }

    /**
     * Details changed, from the model's own `updated` event — so that every path that writes them
     * (the edit forms, the API, an upload, a saved merge) is traced without each having to remember.
     */
    public static function detailsChanged(Translation $translation): void
    {
        $fields = array_values(array_filter(self::DETAILS, fn ($f) => $translation->wasChanged($f)));
        if ($fields === []) {
            return;
        }

        $values = [];
        foreach (self::DETAIL_VALUES as $field) {
            if (in_array($field, $fields, true)) {
                $values[$field] = $translation->{$field};
            }
        }

        self::log(self::DETAILS_CHANGED, $translation, [
            'fields' => $fields,
            'values' => $values,
            // The same save also replaced the lines — an upload, or a merge saved with a new
            // description. Said, so the screen does not read two events as two separate acts.
            'with_content' => $translation->wasChanged('file_hash'),
        ]);
    }

    /**
     * A publication turned down. There is no translation to hang it on — the point is that none
     * was written — so the context is what the request itself said.
     */
    public static function refused(Request $request, string $code): AuditLog
    {
        return AuditLog::log(self::REFUSED, self::actorId($request), 'Translation', null, [
            'code' => $code,
            'source_language' => $request->input('source_language'),
            'target_language' => $request->input('target_language'),
            'sent' => array_filter([
                'steam_id' => $request->input('steam_id'),
                'game_name' => $request->input('game_name'),
                'game_pick' => is_array($request->input('game_pick')) ? $request->input('game_pick') : null,
                'game_read' => $request->has('game_read'),
            ], fn ($v) => $v !== null && $v !== false),
        ] + self::via($request), $request);
    }

    /**
     * One line saying what an event did, for the screen's list.
     *
     * ⚠ Reads rows of every age: keys added on 2026-10-05 are absent from older ones, and an
     * absent key says nothing rather than something false.
     */
    public static function describe(AuditLog $event): string
    {
        $m = $event->metadata ?? [];
        $lines = isset($m['line_count']) ? number_format((int) $m['line_count']) . ' lines' : null;

        $said = match ($event->action) {
            self::PUBLISHED => match (true) {
                !empty($m['is_update']) => 'Update',
                !empty($m['forked_from']) => 'New fork of #' . $m['forked_from'],
                !empty($m['is_branch']) => 'New branch',
                default => 'New translation',
            } . ($lines ? " — {$lines}" : ''),

            self::CONTENT_SAVED => (($m['how'] ?? null) === 'local_merge'
                    ? 'Merged with the game\'s file in the browser'
                    : 'Edited in the site\'s editor')
                . ': ' . (int) ($m['modified'] ?? 0) . ' changed, ' . (int) ($m['deleted'] ?? 0) . ' removed'
                . (!empty($m['branches_taken'])
                    ? ', lines taken from ' . count($m['branches_taken']) . ' branch' . (count($m['branches_taken']) > 1 ? 'es' : '')
                    : ''),

            self::DETAILS_CHANGED => 'Changed: ' . implode(', ', array_map(
                    fn ($field) => (self::DETAIL_NAMES[$field] ?? $field) . self::detailValue($field, $m['values'] ?? []),
                    $m['fields'] ?? []
                )) . (!empty($m['with_content']) ? ' (with new lines)' : ''),

            self::FORKED => 'Branch #' . ($m['from_branch'] ?? '?') . ' turned into its own translation',

            self::GAME_CHANGED => 'Moved from ' . ($m['from']['name'] ?? '—') . ' to ' . ($m['to']['name'] ?? '—')
                . (isset($m['rows']) && count($m['rows']) > 1 ? ' (' . count($m['rows']) . ' rows of the lineage)' : ''),

            self::DELETED => 'Deleted ' . (self::DELETED_BY[$m['how'] ?? ''] ?? '')
                . (($lived = self::lifespan($event)) !== null ? ', after ' . self::duration($lived) : ''),

            self::REFUSED => (self::REFUSALS[$m['code'] ?? ''] ?? ($m['code'] ?? 'Refused'))
                . (!empty($m['sent']['game_name']) ? ' — sent "' . $m['sent']['game_name'] . '"' : '')
                . (!empty($m['sent']['steam_id']) ? ', Steam ' . $m['sent']['steam_id'] : ''),

            default => $event->action,
        };

        return trim($said);
    }

    /**
     * One line for a run of the same act (TranslationFlowReport::runs), in any order. Updates say
     * how the file grew; anything else says how many times, then what the latest did.
     *
     * @param list<AuditLog> $events
     */
    public static function describeRun(array $events): string
    {
        if (count($events) === 1) {
            return self::describe($events[0]);
        }

        // By time, not by position: the list can be sorted oldest first.
        usort($events, fn ($a, $b) => [$b->created_at->timestamp, $b->id] <=> [$a->created_at->timestamp, $a->id]);
        $newest = $events[0];
        $oldest = $events[count($events) - 1];

        if ($newest->action === self::PUBLISHED
            && isset($newest->metadata['line_count'], $oldest->metadata['line_count'])) {
            return count($events) . ' updates — ' . number_format((int) $oldest->metadata['line_count'])
                . ' → ' . number_format((int) $newest->metadata['line_count']) . ' lines';
        }

        return count($events) . ' times — latest: ' . self::describe($newest);
    }

    /**
     * Where an event came from, as the screen names it, or null when it is not known.
     *
     * ⚠ A publication from before 2026-10-05 carries no `via`, but its row still holds the agent
     * that sent it until `audit:purge-ips` clears it at twelve months: it is READ for that, never
     * shown or copied — one of our programs and its version, or a browser. Only for publications:
     * a deletion from a browser could be its author or an admin, and the agent cannot tell which.
     *
     * ⚠ For the list only. The "Through" card and its filter read `via` alone, since a filter asked
     * of the database cannot read an agent this way — a count that its own filter does not find
     * again would be a count that lies.
     */
    public static function viaOf(AuditLog $event): ?string
    {
        $m = $event->metadata ?? [];
        if (isset($m['via'])) {
            return (self::VIA[$m['via']] ?? $m['via']) . (isset($m['version']) ? ' ' . $m['version'] : '');
        }

        if ($event->action !== self::PUBLISHED || empty($event->user_agent)) {
            return null;
        }

        $client = ClientAgent::ours($event->user_agent);

        return $client === null
            ? self::VIA['site']
            : self::VIA[$client['kind']] . ($client['version'] ? ' ' . $client['version'] : '');
    }

    /**
     * How long a deleted translation lived, in seconds — null when its birth is not known (a row
     * deleted before 2026-10-05 carries no creation date).
     */
    public static function lifespan(AuditLog $deleted): ?int
    {
        $born = $deleted->metadata['created_at'] ?? null;
        if (!is_string($born)) {
            return null;
        }

        return max(0, (int) \Carbon\Carbon::parse($born)->diffInSeconds($deleted->created_at, true));
    }

    /** A span as people say it: "3 hours", "12 days". */
    public static function duration(int $seconds): string
    {
        return \Carbon\CarbonInterval::seconds($seconds)->cascade()->forHumans(['parts' => 1]);
    }

    private static function detailValue(string $field, array $values): string
    {
        if (!array_key_exists($field, $values)) {
            return '';
        }

        $value = $values[$field];

        return ' → ' . match (true) {
            is_bool($value) => $value ? 'yes' : 'no',
            $value === null || $value === '' => 'none',
            $field === 'status' => Translation::STATUSES[$value] ?? (string) $value,
            default => (string) $value,
        };
    }

    /**
     * Who acted. The API sets its account on the request (`setUserResolver`), never on the session
     * guard, so `auth()->id()` alone reads nobody there.
     */
    private static function actorId(?Request $request): ?int
    {
        return $request?->user()?->id ?? auth()->id();
    }

    /**
     * The request being served, or null in a command or a job — where Laravel still hands out a
     * request object, one that describes no visitor (its agent is "Symfony").
     */
    private static function currentRequest(): ?Request
    {
        return app()->runningInConsole() && !app()->runningUnitTests() ? null : request();
    }
}
