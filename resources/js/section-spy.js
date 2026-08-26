import { watchCurrentSection } from './section-position.js';

/**
 * Marks the table-of-contents entry for the section being read.
 *
 * A twelve-entry menu on a page metres long, and nothing said where you were: hovering lit an
 * entry up, clicking left no trace, and scrolling changed nothing at all. The style for it had
 * been written — `.docs-nav-item.active` was sitting in the stylesheet with its purple border —
 * and no code ever set the class. Dead CSS waiting for a feature that never arrived.
 *
 * 🔴 **Two entries can be current at once, and that is not a bug.** Once the menu lists
 * sub-entries, being inside "Mod defaults" means being inside "The Manager" as well — and a menu
 * that lit only the deepest one would drop the section heading the reader is under, while a menu
 * that lit only the section would never move once you entered it. So the section stays lit and the
 * sub-entry lights too.
 *
 * Generic: it is given a selector for the links, a selector for the places on the page, and the
 * class to set. It knows nothing about documentation.
 *
 * `onCurrent` is handed the trail — deepest first — whenever it changes, for callers that need to
 * do more than set a class. It exists so that this file does NOT have to learn what a collapsible
 * menu is: the caller opens and closes, this one only ever says where the reader is.
 */
export function createSectionSpy({ root, linkSelector, activeClass = 'active',
                                   anchorSelector = 'section[id]', onCurrent }) {
    const links = [...document.querySelectorAll(linkSelector)]
        .filter(link => link.getAttribute('href')?.startsWith('#'));

    if (links.length === 0) return () => {};

    /**
     * Keep the marked entry inside its own scroll box.
     *
     * Once a menu lists sub-entries it is taller than the screen and scrolls on its own, so
     * "the current entry is highlighted" and "the reader can see the highlight" stop being the
     * same statement. Half the menu can be marked somewhere nobody is looking.
     *
     * ⚠ Written as arithmetic on scrollTop rather than scrollIntoView, deliberately: that method
     * walks up EVERY scrollable ancestor, the document included, so it can drag the page itself
     * while the reader is scrolling it — the menu fighting the hand that moves it. This touches
     * one box and only when the entry is actually outside it.
     */
    const keepVisible = (link) => {
        const box = link.closest('[data-nav-scroll]');
        if (!box || box.scrollHeight <= box.clientHeight) return;

        // Measured from rectangles rather than offsetTop: offsetTop is relative to whichever
        // ancestor happens to be positioned, and this box is `position: sticky` — so the two
        // numbers are in different frames of reference and the arithmetic silently drifts.
        const linkRect = link.getBoundingClientRect();
        const boxRect = box.getBoundingClientRect();
        const top = linkRect.top - boxRect.top + box.scrollTop;
        const bottom = top + linkRect.height;
        const margin = 24;

        if (top < box.scrollTop + margin) {
            box.scrollTop = Math.max(0, top - margin);
        } else if (bottom > box.scrollTop + box.clientHeight - margin) {
            box.scrollTop = bottom - box.clientHeight + margin;
        }
    };

    /** The id itself, plus the section it hangs under when it is a sub-entry. */
    const trail = (id) => {
        const ids = [id];
        const element = document.getElementById(id);
        const section = element?.closest('section[id]');
        if (section && section.id !== id) ids.push(section.id);
        return ids;
    };

    /** Mark one trail as current: tell the caller first, then light the links. */
    const apply = (current) => {
        // ⚠ BEFORE the links, not after: the caller may open or close part of the menu, which
        // moves every entry below it. keepVisible() measures rectangles, so it has to run on the
        // geometry the reader will actually see.
        if (onCurrent) onCurrent(current);

        links.forEach(link => {
            const target = link.getAttribute('href').slice(1);
            const matches = current.includes(target);
            link.classList.toggle(activeClass, matches);
            // Spoken as well as shown: the menu is a set of links and one of them is the page
            // the reader is on, which is exactly what aria-current means.
            //
            // ⚠ Only the DEEPEST match gets it. `aria-current` answers "which one is it", and a
            // screen reader announcing two current items answers nothing — whereas the visual
            // highlight can carry both because a reader sees the nesting.
            if (matches && target === current[0]) {
                link.setAttribute('aria-current', 'true');
                keepVisible(link);
            } else {
                link.removeAttribute('aria-current');
            }
        });
    };

    /**
     * 🔴 **Following the scroll is right for the wheel and wrong for a click.**
     *
     * Scrolling by hand is a search: the reader passes through sections and the menu follows,
     * which is the whole point. Clicking a link is not a search — the destination is already
     * decided. But the page scrolls there smoothly, so without this the spy walks every section
     * on the way and the menu opens and shuts in cascade for the length of the journey, its
     * height jumping under the cursor the reader just used.
     *
     * So a click applies the destination AT ONCE and then stops listening until the page has
     * come to rest: one close, one open, no flicker in between.
     *
     * ⚠ Detected by a timer re-armed on every scroll event rather than by `scrollend`, which is
     * still missing from some browsers. It also covers the case with no scroll at all — a link to
     * a section already on screen fires no event, and the timer simply expires.
     */
    let held = false;
    let holdTimer = null;

    const releaseSoon = () => {
        clearTimeout(holdTimer);
        holdTimer = setTimeout(() => { held = false; }, 120);
    };

    const onLinkClick = (event) => {
        const link = event.currentTarget;
        const target = link.getAttribute('href')?.slice(1);
        if (!target || !document.getElementById(target)) return;

        held = true;
        apply(trail(target));
        releaseSoon();
    };

    links.forEach(link => link.addEventListener('click', onLinkClick));
    window.addEventListener('scroll', releaseSoon, { passive: true });

    const stop = watchCurrentSection(root, (id) => {
        if (held) return;
        apply(id ? trail(id) : []);
    }, undefined, anchorSelector);

    return () => {
        stop();
        clearTimeout(holdTimer);
        links.forEach(link => link.removeEventListener('click', onLinkClick));
        window.removeEventListener('scroll', releaseSoon);
    };
}
