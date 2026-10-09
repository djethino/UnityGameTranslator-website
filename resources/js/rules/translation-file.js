/**
 * Which keys of a translations.json are metadata, which are lines of the game's text, and which
 * are neither.
 *
 * 🔴 A port of `common/TranslationFileKeys.cs` and of app/Support/TranslationFileKeys.php, held to the
 * same cases: resources/corpus/rules/translation_file_keys.json (`node --test resources/js/rules/`).
 *
 * 🔴 **Not « starts with an underscore »** (2026-10-09): a game's text can begin with one, and the
 * prefix rule hid it from every screen. Metadata is the `properties` of
 * resources/spec/translation-file/schema.json; another underscore name is a line when its value is
 * shaped like one ({v, …}), and left alone otherwise — a newer writer's metadata.
 * tests/Unit/TranslationFileKeysTest.php holds this list equal to the PHP one.
 */
export const METADATA_KEYS = Object.freeze([
    '_engine_version', '_uuid', '_source_language', '_target_language', '_local_changes',
    '_metadata_dirty', '_game', '_source', '_forked_from', '_fonts', '_font_overrides',
    '_image_replacements', '_exclusions', '_variables', '_settings',
]);

const metadata = new Set(METADATA_KEYS);

/** Is this key one of the names the tools write ABOUT the file? */
export function isMetadataKey(key) {
    return typeof key === 'string' && metadata.has(key);
}

/** Is this value shaped like a line — a JSON object with a `v` member? */
export function lineShaped(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value) && 'v' in value;
}

/** Is a key a line of the game's text, its value being (or not) shaped like one? The C#'s IsLine. */
export function isLine(key, isShaped) {
    if (typeof key !== 'string' || metadata.has(key)) return false;
    // An empty key stays what it always was: a line (nothing writes one).
    return key === '' || key[0] !== '_' || isShaped === true;
}

/** Is this entry of a parsed file a line of the game's text? */
export function isLineEntry(key, value) {
    return isLine(key, lineShaped(value));
}
