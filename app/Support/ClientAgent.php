<?php

namespace App\Support;

/**
 * Reads which of our programs is calling, from the User-Agent it sends.
 *
 * ⚠ This is a DECLARATION, never a proof. A caller writes its own User-Agent, so what comes out of
 * here may be displayed and may group lines together, but must never grant anything. Authorisation
 * is decided by the token alone.
 *
 * One place, because two callers need the same answer: `POST /auth/device`, where the program
 * speaks before anybody has signed in, and the API middleware, which fills the field in for tokens
 * issued before this existed.
 */
class ClientAgent
{
    public const MOD = 'mod';
    public const MANAGER = 'manager';
    public const OTHER = 'other';

    /**
     * What is calling, or null when the agent belongs to no program of ours.
     *
     * Returns ['kind' => ..., 'version' => ..., 'variant' => ...].
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
        if (preg_match('#^UnityGameTranslatorManager/([0-9]+\.[0-9]+)#', $userAgent, $m)) {
            return ['kind' => self::MANAGER, 'version' => $m[1], 'variant' => null];
        }

        // UnityGameTranslator/0.12.0 (BepInEx6-Mono) — the loader is optional: it is only known
        // once the adapter has been set, which may happen after the first call.
        if (preg_match('#^UnityGameTranslator/([0-9]+\.[0-9]+)[0-9.]*(?:\s*\(([^)]{1,32})\))?#', $userAgent, $m)) {
            return [
                'kind' => self::MOD,
                'version' => $m[1],
                'variant' => isset($m[2]) && $m[2] !== '' ? $m[2] : null,
            ];
        }

        return ['kind' => self::OTHER, 'version' => null, 'variant' => null];
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
}
