<?php

namespace App\Support;

/**
 * Reads which game a program is speaking for, from the header it sends on an ordinary call.
 *
 * ### Why this exists at all, and why it is not the device flow
 *
 * 🔴 A game was declared **only at link time** (`POST /auth/device`). So an access created before
 * that existed — or created by a version that did not declare — stayed nameless for ever, while the
 * program went on calling us several times an hour with the game right there in front of it. The
 * link is the one moment we listened, which is the one moment we had nothing more to learn.
 *
 * ⚠ This is a DECLARATION, never a proof — same rule as {@see ClientAgent}. A caller writes its own
 * header. It may be displayed and it may group lines, and it must never grant anything: only the
 * token authorises, and a caller can therefore only ever mislabel its own line, under its own
 * account.
 *
 * ### Why base64 rather than two plain headers
 *
 * A game is called 龙胤立志传 as readily as LoneStar, and an HTTP header value is not a place for
 * UTF-8: it is latin-1 by specification, and .NET's client throws on the way out rather than
 * mangling on the way in. So the payload is the **same JSON the device flow already sends**,
 * base64url-encoded — one shape of declaration, decided in one place, whichever door it comes
 * through.
 */
class GameDeclaration
{
    /** Big enough for a 120-character name in any script, small enough to refuse a payload. */
    private const MAX_HEADER = 512;

    public const HEADER = 'X-UGT-Game';

    /**
     * ['game_id' => ?string, 'game_name' => ?string], or null when there is nothing usable.
     *
     * ⚠ Every failure returns null rather than throwing. This runs inside authentication, on every
     * authenticated call: a malformed header is a client sending nonsense about ITSELF, and turning
     * that into a 500 would let anybody break their own access by writing one bad byte.
     */
    public static function parse(?string $header): ?array
    {
        $header = trim((string) $header);

        if ($header === '' || strlen($header) > self::MAX_HEADER) {
            return null;
        }

        $json = base64_decode(strtr($header, '-_', '+/'), true);

        if ($json === false) {
            return null;
        }

        $payload = json_decode($json, true);

        if (!is_array($payload)) {
            return null;
        }

        // Digits only, and short. The Steam id is the identity half — it is what lets one game hold
        // one access — so anything that is not one is not an id, and is dropped rather than stored
        // as a curiosity.
        $id = isset($payload['game_id']) && is_string($payload['game_id'])
            ? trim($payload['game_id'])
            : '';
        $id = ($id !== '' && strlen($id) <= 32 && ctype_digit($id)) ? $id : null;

        // The label half. Two different games can carry the same one, which is exactly why it
        // travels as a name and never as an identity.
        $name = isset($payload['game_name']) && is_string($payload['game_name'])
            ? trim($payload['game_name'])
            : '';
        $name = $name !== '' ? mb_substr($name, 0, 120) : null;

        if ($id === null && $name === null) {
            return null;
        }

        return ['game_id' => $id, 'game_name' => $name];
    }

    public const DEVICE_HEADER = 'X-UGT-Device';

    /**
     * The machine identifier a program declares, or null when there is nothing usable.
     *
     * 🔴 **A random number the machine drew once — never a measurement of it.** The tempting source
     * was `Secrets.MachineSecret()`, already stable and ready: machine name, user name, OS. It is
     * exactly the wrong one, and the reason is worth keeping: those have tiny entropy and are often
     * a real first name, so a digest of them CONFIRMS a guess instead of hiding one.
     *
     * ⚠ Shape-checked and nothing more. A declaration is never a proof — the same rule as the game
     * and the User-Agent — so this can only ever group the caller's own lines, under its own
     * account, and the server salts it per user before storing it ({@see ApiToken::deviceSlotFor}).
     */
    public static function parseDevice(?string $header): ?string
    {
        $header = trim((string) $header);

        // Long enough to be somebody's draw, short enough to refuse a payload. Hex and dashes cover
        // both a plain random string and a GUID, and exclude anything trying to be structured.
        return preg_match('/^[A-Za-z0-9-]{16,64}$/', $header) === 1 ? $header : null;
    }
}
