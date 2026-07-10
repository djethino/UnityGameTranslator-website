/**
 * Off-main-thread loader for the live edit session.
 *
 * Fetching + JSON-parsing the whole translation file (MBs on big RPGs)
 * froze the page's main thread for ~200 ms on every mod push — cursor
 * and clicks stalled every ~10 s while the game translates. This worker
 * owns the fetch, the parse, the normalization and the diff against the
 * previously seen content; the page only ever receives the handful of
 * entries that actually changed (or the full content on first load).
 */

let cache = null;

function normalizeLineEndings(text) {
    if (typeof text !== 'string') return text;
    return text.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
}

function getValue(entry) {
    if (entry === null || entry === undefined) return '';
    if (typeof entry === 'object') return entry.v || '';
    return String(entry);
}

function getTag(entry) {
    if (entry === null || entry === undefined) return 'A';
    if (typeof entry === 'object') return entry.t || 'A';
    return 'A';
}

self.onmessage = async (event) => {
    const { id, url } = event.data;
    try {
        const response = await fetch(url, { headers: { 'Accept': 'application/json' } });
        if (!response.ok) {
            throw new Error(response.status === 410 ? 'expired' : 'load_failed');
        }
        const payload = await response.json();

        // Normalize + strip metadata keys, same rules as the page had
        const fresh = {};
        for (const [key, value] of Object.entries(payload.content || {})) {
            if (key.startsWith('_')) continue;
            const normalizedKey = normalizeLineEndings(key);
            let normalizedValue = value;
            if (typeof value === 'object' && value !== null && 'v' in value) {
                normalizedValue = { ...value, v: normalizeLineEndings(value.v) };
            } else if (typeof value === 'string') {
                normalizedValue = normalizeLineEndings(value);
            }
            fresh[normalizedKey] = normalizedValue;
        }

        if (cache === null) {
            cache = fresh;
            self.postMessage({ id, full: fresh });
            return;
        }

        const changed = {};
        const removed = [];
        for (const key of Object.keys(fresh)) {
            const previous = cache[key];
            if (previous === undefined
                || getValue(previous) !== getValue(fresh[key])
                || getTag(previous) !== getTag(fresh[key])) {
                changed[key] = fresh[key];
            }
        }
        for (const key of Object.keys(cache)) {
            if (!(key in fresh)) removed.push(key);
        }
        cache = fresh;

        self.postMessage({ id, changed, removed });
    } catch (error) {
        self.postMessage({ id, error: error.message });
    }
};
