/**
 * Keeps the reader where they were when a link reloads the same screen — a span, a sort, a page
 * of a table. Any link (or an element around links) carrying `data-keeps-scroll` joins in.
 *
 * 🔴 **A full reload, deliberately, rather than patching the page in place.** On the admin screens
 * nearly everything depends on the span or the sort; letting the server re-render the page makes
 * "two parts showing two different spans" impossible BY CONSTRUCTION. The only thing worth keeping
 * across the reload is the scroll position. (Lived inline in the Analytics view until 2026-10-19;
 * moved here when the Mod usage tables needed it too, and the sortable headers of every admin
 * table with it.)
 *
 * ⚠ sessionStorage, not the URL: a scroll offset in the address bar would be shared, bookmarked
 * and mailed around, and it means nothing on anybody else's screen. Keyed by path, so a position
 * saved on one screen is never applied to another.
 */
export function keepScroll() {
    const KEY = 'ugt.keep-scroll:' + window.location.pathname;

    // Delegated on the document: links rendered later, or by a component, need nothing wired up.
    document.addEventListener('click', (event) => {
        if (!event.target.closest('[data-keeps-scroll]')) {
            return;
        }
        try {
            sessionStorage.setItem(KEY, String(window.scrollY));
        } catch (e) {
            // Private windows and blocked site data throw here. Losing the position is a small
            // annoyance; a broken link would not be.
            console.warn('[keep-scroll] position not saved:', e);
        }
    });

    let saved = null;
    try {
        saved = sessionStorage.getItem(KEY);
        sessionStorage.removeItem(KEY);
    } catch (e) {
        console.warn('[keep-scroll] position not read:', e);
        return;
    }
    if (saved === null) {
        return;
    }

    const y = parseInt(saved, 10) || 0;

    // ⚠ Charts and images size themselves late, so the page is shorter than its final height for
    // a moment and an early scroll is clamped short. The page GROWING is what makes a new try
    // worth it: reapplied on each resize until the position is reached — and given up the moment
    // the reader scrolls on their own, whose hand always wins.
    const reached = () => Math.abs(window.scrollY - y) <= 2;
    const observer = new ResizeObserver(() => {
        window.scrollTo(0, y);
        if (reached()) {
            stop();
        }
    });
    const stop = () => {
        observer.disconnect();
        for (const type of ['wheel', 'touchstart', 'keydown']) {
            window.removeEventListener(type, stop);
        }
    };
    for (const type of ['wheel', 'touchstart', 'keydown']) {
        window.addEventListener(type, stop, { passive: true });
    }

    window.scrollTo(0, y);
    if (!reached()) {
        observer.observe(document.documentElement);
    } else {
        stop();
    }
}
