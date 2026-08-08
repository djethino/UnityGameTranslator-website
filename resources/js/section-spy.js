import { watchCurrentSection } from './section-position.js';

/**
 * Marks the table-of-contents entry for the section being read.
 *
 * A twelve-entry menu on a page metres long, and nothing said where you were: hovering lit an
 * entry up, clicking left no trace, and scrolling changed nothing at all. The style for it had
 * been written — `.docs-nav-item.active` was sitting in the stylesheet with its purple border —
 * and no code ever set the class. Dead CSS waiting for a feature that never arrived.
 *
 * Generic: it is given a selector for the links and the class to set, and knows nothing else.
 */
export function createSectionSpy({ root, linkSelector, activeClass = 'active' }) {
    const links = [...document.querySelectorAll(linkSelector)]
        .filter(link => link.getAttribute('href')?.startsWith('#'));

    if (links.length === 0) return () => {};

    return watchCurrentSection(root, (id) => {
        links.forEach(link => {
            const matches = link.getAttribute('href') === `#${id}`;
            link.classList.toggle(activeClass, matches);
            // Spoken as well as shown: the menu is a set of links and one of them is the page
            // the reader is on, which is exactly what aria-current means.
            if (matches) {
                link.setAttribute('aria-current', 'true');
            } else {
                link.removeAttribute('aria-current');
            }
        });
    });
}
