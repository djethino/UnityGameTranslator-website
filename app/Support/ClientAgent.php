<?php

namespace App\Support;

/**
 * Reads which of our programs is calling, from the User-Agent it sends.
 *
 * ⚠ This is a DECLARATION, never a proof. A caller writes its own User-Agent, so what comes out of
 * here may be displayed and may group lines together, but must never grant anything. Authorisation
 * is decided by the token alone.
 *
 * 🔴 **One parser, because there used to be two and they disagreed.** `AnalyticsEvent::detectClient`
 * read the full version while this one truncated it to major.minor — so the same call was `0.12.1`
 * in the usage inventory and `0.12` on the linked-devices page, which is exactly the distinction
 * that page exists to show. Nothing justified the truncation: no comment, no migration
 * (`api_tokens.client_version` is 16 characters, enough for `0.12.1-beta.1`), only a test freezing
 * whatever the regex happened to do.
 *
 * **A parser does not lose information. Whoever wants a shorter form shortens it at the point of
 * use.**
 *
 * ⚠ **Nothing here is validated against a list — not a hard-coded one, and not the catalogue.** The
 * guard is the SHAPE of what arrives (against injection and against a value that would not fit),
 * never its content: the set of loaders in circulation is built from what clients send. Bounding it
 * by `catalogs/loaders.json` was considered and rejected on 2026-08-29 — that file is the current
 * state of what we INSTALL, so dropping a loader from it would hide the copies still running on it,
 * which is the exact moment one needs to see them. Reasoning in
 * `analyse/version-inventory-admin.md`.
 */
class ClientAgent
{
    public const MOD = 'mod';
    public const MANAGER = 'manager';
    public const OTHER = 'other';

    /**
     * What is calling, or null when the agent belongs to no program of ours.
     *
     * Returns ['kind' => ..., 'version' => ..., 'variant' => ..., 'legacy' => bool].
     *
     * ⚠ The relay is deliberately unrecognised rather than filed under "other": every mod using
     * the live stream would otherwise have its line relabelled by a piece of our own plumbing,
     * and "other" is the value that is supposed to make somebody look twice.
     */
    public static function parse(?string $userAgent): ?array
    {
        $userAgent = trim((string) $userAgent);

        if ($userAgent === '' || str_starts_with($userAgent, 'UnityGameTranslator-SSE/')) {
            return null;
        }

        // UnityGameTranslatorManager/0.1.1 — checked first: it starts with the mod's own name, so
        // the looser pattern would swallow it.
        if (preg_match('#^UnityGameTranslatorManager/(\S+)#', $userAgent, $m) === 1) {
            return [
                'kind' => self::MANAGER,
                'version' => self::cleanVersion($m[1]),
                'variant' => null,
                'legacy' => false,
            ];
        }

        // UnityGameTranslator/0.12.0 (BepInEx6-Mono) — the loader is optional: it is only known
        // once the adapter has been set, which may happen after the first call.
        if (preg_match('#^UnityGameTranslator(?:-Mod)?/(\S+)(?:\s+\(([^)]+)\))?#', $userAgent, $m) === 1) {
            return [
                'kind' => self::MOD,
                'version' => self::cleanVersion($m[1]),
                'variant' => self::cleanVariant($m[2] ?? null),
                // 🔴 A build from before is recognised by the ABSENCE of the loader, not by the
                // number. Testing for the literal "1.0" would work until the mod actually reaches
                // 1.0 — and that release would then be filed among the builds that cannot
                // decompress, which is the one row that decides whether JSON compression can be
                // turned on. The parenthesis is the thing that changed on 2026-08-20.
                'legacy' => ($m[2] ?? null) === null,
            ];
        }

        return ['kind' => self::OTHER, 'version' => null, 'variant' => null, 'legacy' => false];
    }

    /**
     * The same answer, but only for our own programs — null for a browser, a script, or anything
     * unrecognised.
     *
     * ⚠ Two readings of one parse, and the difference matters. Linking a token must attribute
     * SOMETHING to every caller, hence `other`; counting what we ship must count nothing at all for
     * a caller that is not ours, or the inventory stops being an inventory.
     */
    public static function ours(?string $userAgent): ?array
    {
        $client = self::parse($userAgent);

        if ($client === null || $client['kind'] === self::OTHER) {
            return null;
        }

        return $client;
    }

    /**
     * How a mod loader is written on screen. Unknown values are shown as they came: a loader we
     * have not heard of is still worth reading, and inventing a label for it would hide it.
     */
    public static function loaderLabel(?string $variant): ?string
    {
        return match ($variant) {
            null, '' => null,
            'BepInEx5' => 'BepInEx 5',
            'BepInEx6-Mono' => 'BepInEx 6 (Mono)',
            'BepInEx6-IL2CPP' => 'BepInEx 6 (IL2CPP)',
            'MelonLoader-Mono' => 'MelonLoader (Mono)',
            'MelonLoader-IL2CPP' => 'MelonLoader (IL2CPP)',
            default => $variant,
        };
    }

    /**
     * 🔴 **A User-Agent is written by whoever is calling, so none of it is trusted.**
     *
     * Anyone can send `UnityGameTranslator/<script>alert(1)</script> (AAAA…)` and, without this,
     * it lands in a table and then on an admin screen. Blade escapes it, so this is not about
     * script injection — it is about a stranger choosing what our own measurements say, and about
     * a table whose row count they control.
     *
     * Anything not shaped like a version becomes null: "we do not know", which is true, rather
     * than a value invented by the caller. Which versions are real is decided separately, by
     * `KnownReleases`, against what we have actually published.
     */
    private static function cleanVersion(?string $raw): ?string
    {
        $value = trim((string) $raw);

        return preg_match('/^\d{1,4}(\.\d{1,4}){0,3}(-[A-Za-z0-9.]{1,12})?$/', $value) === 1
            ? $value
            : null;
    }

    /**
     * The mod loader, in the shape the adapters actually report ("BepInEx6-IL2CPP",
     * "MelonLoader-Mono"). Deliberately a shape and not a fixed list: a new adapter must not need
     * a website deployment to be counted, and one we stopped shipping must not vanish from the
     * measurements while copies are still running it.
     */
    private static function cleanVariant(?string $raw): ?string
    {
        $value = trim((string) $raw);

        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,23}$/', $value) === 1
            ? $value
            : null;
    }
}
