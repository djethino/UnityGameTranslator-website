/**
 * The site's top bar steps aside while the reader goes down a page, and comes back the moment they
 * scroll up.
 *
 * Asked for on 2026-09-30, for the documentation first: a bar that scrolls away with the page means
 * going all the way back up to reach Games or the account; a bar pinned for good takes 65px off
 * every screen for the whole visit, which a phone feels. Hiding it on the way down and bringing it
 * back on the way up is what the mobile browsers already do with their own address bar, so the
 * gesture is one everybody knows.
 *
 * ── What follows it ─────────────────────────────────────────────────────────────────────────────
 * 🔴 `--site-bar-offset` on the root: the height the bar takes at the top of the screen right now,
 * 0 while it is away. Everything else that sticks to the top of the viewport sits BELOW it through
 * that variable — the documentation menu, the analytics period bar, the editors' floating search —
 * and `scroll-padding-top` uses it, so an anchor never lands under the bar. A new element pinned to
 * the top of the screen must use it too, or the bar will cover it when it comes back.
 *
 * ── When it does NOT move ───────────────────────────────────────────────────────────────────────
 *   - near the top of the page: there is nothing to make room for, and a bar that vanished while
 *     the page is at rest would read as broken;
 *   - while one of its own menus is open (a language list, the account menu, the phone menu): the
 *     list would go with it, out from under the pointer;
 *   - while the keyboard is inside it: somebody tabbing through the links must see where they are.
 *
 * ── A jump to an anchor sends it away ───────────────────────────────────────────────────────────
 * Following an in-page link (#install-manager…) is a decision about what to read, not a search: the
 * bar steps aside so the heading arrives at the top unobscured, and the direction of that scroll is
 * ignored until the reader moves the page themselves again. Otherwise a jump UP would bring the bar
 * back straight over the heading it was aimed at.
 *
 * ⚠ No timer anywhere (project rule: nothing waits). Every change answers an event: a scroll, a
 * click, a key, a change of the bar's own size.
 */

export function startSiteBar() {
    const bar = document.querySelector('[data-site-bar]');
    if (!bar) return;

    const root = document.documentElement;
    let hidden = false;
    let lastY = window.scrollY;
    // How far the page has moved in the current direction: + going down, - going up.
    let travel = 0;
    // A jump to an anchor is under way; its scroll is not the reader's own.
    let jumping = false;

    const height = () => bar.offsetHeight;

    const publish = () => {
        root.style.setProperty('--site-bar-offset', hidden ? '0px' : `${height()}px`);
    };

    const setHidden = (value) => {
        if (value === hidden) return;
        hidden = value;
        bar.classList.toggle('site-bar-hidden', hidden);
        publish();
    };

    /** One of the bar's own Alpine menus is open. */
    const menuOpen = () => {
        const Alpine = window.Alpine;
        if (!Alpine) return false;
        for (const el of bar.querySelectorAll('[x-data]')) {
            const data = Alpine.$data(el);
            if (data && (data.open === true || data.mobileMenuOpen === true)) return true;
        }
        // The bar's own x-data (the phone menu) is on the bar itself.
        const own = Alpine.$data(bar);
        return !!(own && own.mobileMenuOpen === true);
    };

    const mustStay = () => menuOpen() || !!bar.querySelector(':focus-visible');

    const onScroll = () => {
        const y = window.scrollY;
        const dy = y - lastY;
        lastY = y;

        // At the top there is nothing to make room for.
        if (y <= height()) {
            jumping = false;
            travel = 0;
            setHidden(false);
            return;
        }

        if (jumping || dy === 0) return;

        travel = (dy > 0) === (travel > 0) ? travel + dy : dy;

        // 🔴 Two distances, and neither is a wait. Down: a whole bar's height, so the ordinary
        // back-and-forth of reading a paragraph does not make it blink. Up: a quarter of it — enough
        // to be a gesture rather than the last reverse jitter of a trackpad's momentum, little enough
        // that the bar answers the first real move up.
        if (travel >= height() && !mustStay()) {
            setHidden(true);
        } else if (-travel >= height() / 4) {
            setHidden(false);
        }
    };

    /** The reader moved the page themselves: a jump in progress, if any, is over. */
    const ownMove = () => { jumping = false; };

    const onClick = (event) => {
        const link = event.target.closest?.('a[href^="#"]');
        if (!link) return;
        const id = link.getAttribute('href').slice(1);
        if (!id || !document.getElementById(id)) return;
        // Before the browser scrolls: `scroll-padding-top` reads the variable when the scroll starts.
        jumping = true;
        travel = 0;
        if (window.scrollY > height()) setHidden(true);
    };

    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('wheel', ownMove, { passive: true });
    window.addEventListener('touchstart', ownMove, { passive: true });
    window.addEventListener('keydown', ownMove);
    // Dragging the scrollbar sends no wheel. The click on an anchor link comes AFTER its own
    // pointerdown, so a jump is not cancelled by the press that started it.
    window.addEventListener('pointerdown', ownMove, { passive: true });
    document.addEventListener('click', onClick, true);

    // Keyboard focus coming into the bar brings it back.
    bar.addEventListener('focusin', () => {
        if (bar.querySelector(':focus-visible')) setHidden(false);
    });

    // Its height changes when the phone menu unfolds inside it, when fonts arrive, when the window
    // is resized: whatever sits below it must follow.
    new ResizeObserver(publish).observe(bar);

    publish();
}
