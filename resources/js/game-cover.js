// A game's picture, in a portrait frame — whatever shape the picture really is.
//
// 🔴 The site does not know the shape before the picture arrives: a game's image comes from IGDB
// (a portrait cover, 3:4), Steam (the store header, 460×215) or RAWG (an in-game screenshot, 16:9),
// and nothing stored says which. Cropped to fill a portrait frame, a header keeps its middle third —
// half a title, nobody's face. So the frame decides once the picture has loaded:
//
// | the picture is | it is shown |
// |---|---|
// | taller than wide — a cover | filling the frame, as a cover is meant to be seen |
// | wider than tall — a banner or a screenshot | whole, centred, on a blurred copy of itself filling the frame |
//
// ⚠ Decided here and nowhere else: the Blade component (`components/game-cover.blade.php`) and the
// pages that draw a game from a search (`gameCover()` below) build the same markup, and a delegated
// `load` listener fits every one of them — also when a page swaps the picture's `src` later.
// Inline `onload` would not do: the site's CSP carries a nonce, which makes browsers ignore every
// inline handler.

const FRAME = '[data-game-cover]';
const PICTURE = '[data-game-cover-image]';
const BACKDROP = '[data-game-cover-backdrop]';

/** Fit one picture to its frame, from the size it actually has. */
function fit(img) {
    const frame = img.closest(FRAME);
    if (!frame || !img.naturalWidth) return;

    const backdrop = frame.querySelector(BACKDROP);
    const wide = img.naturalWidth > img.naturalHeight;

    img.classList.toggle('object-contain', wide);
    img.classList.toggle('object-cover', !wide);
    if (backdrop) {
        if (wide) backdrop.src = img.currentSrc || img.src;
        backdrop.hidden = !wide;
    }
}

/**
 * A picture that fails to load leaves the frame's own grey background rather than the browser's
 * broken-image icon. A later `src` that loads shows it again (the `load` listener).
 */
function fail(img) {
    img.hidden = true;
    const backdrop = img.closest(FRAME)?.querySelector(BACKDROP);
    if (backdrop) backdrop.hidden = true;
}

export function startGameCovers() {
    // `load` and `error` do not bubble: listened for on the way down instead.
    document.addEventListener('load', (event) => {
        if (event.target instanceof HTMLImageElement && event.target.matches(PICTURE)) {
            event.target.hidden = false;
            fit(event.target);
        }
    }, true);
    document.addEventListener('error', (event) => {
        if (event.target instanceof HTMLImageElement && event.target.matches(PICTURE)) fail(event.target);
    }, true);

    // The bundle runs after the page has been parsed: pictures already in the browser's cache have
    // loaded before anybody was listening.
    document.querySelectorAll(PICTURE).forEach((img) => {
        if (!img.complete) return;
        if (img.naturalWidth) fit(img); else if (img.getAttribute('src')) fail(img);
    });
}

/**
 * The same frame, built for a page that draws a game it has just been handed (a search hit).
 * `sizeClasses` sizes and rounds the frame; the picture fills it.
 */
export function gameCover(src, alt, sizeClasses) {
    const frame = document.createElement('span');
    frame.className = 'relative block overflow-hidden bg-gray-700 flex-shrink-0 ' + sizeClasses;
    frame.dataset.gameCover = '';

    const backdrop = document.createElement('img');
    backdrop.className = 'absolute inset-0 w-full h-full object-cover blur-md scale-110 brightness-75';
    backdrop.alt = '';
    backdrop.setAttribute('aria-hidden', 'true');
    backdrop.dataset.gameCoverBackdrop = '';
    backdrop.hidden = true;

    const img = document.createElement('img');
    img.className = 'relative w-full h-full object-cover';
    img.alt = alt || '';
    img.dataset.gameCoverImage = '';
    img.src = src;

    frame.append(backdrop, img);
    return frame;
}
