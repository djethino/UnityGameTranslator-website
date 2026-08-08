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

export function currentSectionId(root = document, positionLine = POSITION_LINE) {
    const sections = [...root.querySelectorAll('section[id]')];
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
export function watchCurrentSection(root, onChange, positionLine = POSITION_LINE) {
    let last = null;
    let pending = false;

    const check = () => {
        const id = currentSectionId(root, positionLine);
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
