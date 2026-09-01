/**
 * Which section of a long page the reader is currently on.
 *
 * Shared because two features need exactly this answer and would otherwise carry two copies of
 * it — the reading trail, which records where a jump was taken from, and the table of contents,
 * which shows where you are. They were about to disagree with each other over the same page.
 *
 * The bottom of the document is the case everyone forgets: the last sections can never be
 * scrolled up to the position line, because the page runs out of room first. Without the
 * exception below, the final section of a page is never "the one you are on" — the last entry of
 * a table of contents would never light up, and a link followed from that section would record
 * the section above it as its origin.
 */

export const POSITION_LINE = 120;

/**
 * What counts as "a place on the page".
 *
 * 🔴 One answer for both callers, which is the entire reason this file exists — and it was not.
 * The trail was left on the default, `section[id]`, on the reasoning that "you came from a
 * sub-heading of Configuration" is noise; the table of contents was passed sections AND the
 * anchors it lists. So the two disagreed on the very page they share: the menu highlighted the
 * sub-entry you were reading while the trail offered to take you back to the section above it.
 *
 * ⚠ The reasoning was wrong, not merely inconsistent. `[data-nav-anchor]` is not a sub-heading, it
 * is a place the page itself decided to name and put in the menu — on the documentation, 43 of
 * them against 13 sections. Told "go back to Manager" after leaving "OneClick", a reader has been
 * handed the name of somewhere they were not.
 */
export const SECTION_SELECTOR = 'section[id], [data-nav-anchor][id]';

export function currentSectionId(root = document, positionLine = POSITION_LINE,
                                 selector = SECTION_SELECTOR) {
    const sections = [...root.querySelectorAll(selector)];
    if (sections.length === 0) return null;

    const atBottom = window.innerHeight + window.scrollY
        >= document.documentElement.scrollHeight - 4;
    if (atBottom) return sections[sections.length - 1].id;

    let found = null;
    for (const section of sections) {
        if (section.getBoundingClientRect().top <= positionLine) found = section.id;
    }
    return found || sections[0].id;
}

/**
 * Run a callback whenever the reader's section changes, and once immediately.
 * Returns a function that stops watching.
 */
export function watchCurrentSection(root, onChange, positionLine = POSITION_LINE,
                                    selector = SECTION_SELECTOR) {
    let last = null;
    let pending = false;

    const check = () => {
        const id = currentSectionId(root, positionLine, selector);
        if (id === last) return;
        last = id;
        onChange(id);
    };

    const schedule = () => {
        if (pending) return;
        pending = true;
        requestAnimationFrame(() => { pending = false; check(); });
    };

    window.addEventListener('scroll', schedule, { passive: true });
    window.addEventListener('resize', schedule, { passive: true });
    check();

    return () => {
        window.removeEventListener('scroll', schedule);
        window.removeEventListener('resize', schedule);
    };
}
