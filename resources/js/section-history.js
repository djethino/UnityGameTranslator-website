/**
 * A trail of the sections you have jumped through, with a way back.
 *
 * Long documentation is read by hopping: a cross-link answers the question you had, and then you
 * want to be where you were. The browser's Back button does it, but on a single page full of
 * anchors it also walks you out of the page you are reading, and nobody trusts it enough to try.
 *
 * WHAT COUNTS AS A STEP — only links inside the content, never the menu.
 * The menu is permanently on screen: every section is one click away from anywhere, so a trail
 * repeating it would add nothing and would appear for everyone, which is precisely what this is
 * not for. A cross-link is the opposite: it takes you somewhere you did not pick from a list,
 * and finding your way back means remembering where you were.
 *
 * THE MENU ENDS THE THREAD. It does not add a step, and it does not quietly sit alongside
 * either: it wipes the trail and the panel goes away. A cross-link INTERRUPTS a reading — it
 * takes you off a page you had not finished, which is the whole reason a way back is worth
 * offering. Choosing a section from the menu is not an interruption, it is a decision to leave;
 * whatever thread was running has been abandoned by the reader, and a floating box still
 * offering to return to it would outlive its usefulness for the rest of the session.
 *
 * So: one thread at a time, and the menu starts a new one. Plain scrolling does not — reading on
 * is not leaving.
 *
 * The reader's real position is still measured, for one thing only: a jump records where it was
 * taken FROM, so the section actually left behind is the one inserted.
 *
 * DUPLICATES ARE KEPT. Coming back to a section by another route appends it again rather than
 * moving a cursor: this is a record of where you have been, in order, and A → B → A really did
 * happen. Branching, on the other hand, truncates: going back and taking a different link drops
 * what was ahead, like any undo stack.
 *
 * IT KEEPS NOTHING. No storage, no cookie, no URL. Reading history is exactly the kind of thing
 * a site has no business remembering, and a trail restored the next day would be a claim about
 * where you have been that nobody made.
 *
 * Generic on purpose: it binds to whatever container it is given, and nothing here knows about
 * documentation.
 */

import { currentSectionId, POSITION_LINE } from './section-position.js';

const DEFAULTS = {
    root: document,
    headingSelector: 'h2, h3',
    positionLine: POSITION_LINE,
    max: 25,
};

export function createSectionHistory(options = {}) {
    const config = { ...DEFAULTS, ...options };

    const trail = [];
    let cursor = -1;
    let dismissed = false;

    const panel = buildPanel();
    const list = panel.querySelector('[data-history-list]');
    const backBtn = panel.querySelector('[data-history-back]');
    const forwardBtn = panel.querySelector('[data-history-forward]');
    const closeBtn = panel.querySelector('[data-history-close]');
    const counter = panel.querySelector('[data-history-count]');

    /**
     * What to call a section. The sidebar's wording wins when there is one, so the trail and the
     * menu name the same place the same way — several sections have a heading that differs from
     * their menu entry.
     */
    function labelFor(id) {
        const menuEntry = document.querySelector(`a[href="#${CSS.escape(id)}"]`);
        if (menuEntry && !config.root.contains(menuEntry)) {
            return menuEntry.textContent.trim().slice(0, 40);
        }

        const target = document.getElementById(id);
        if (!target) return id;

        const heading = target.matches(config.headingSelector)
            ? target
            : target.querySelector(config.headingSelector);

        return (heading?.textContent || target.textContent || id).trim().slice(0, 40);
    }

    /** Where the reader is — shared with the table of contents, see section-position.js. */
    const currentSection = () => currentSectionId(config.root, config.positionLine);

    function push(id) {
        // Branching discards what was ahead, like any undo stack
        if (cursor < trail.length - 1) trail.splice(cursor + 1);

        if (trail[trail.length - 1]?.id !== id) {
            trail.push({ id, label: labelFor(id) });
            if (trail.length > config.max) trail.shift();
        }

        cursor = trail.length - 1;
        render();
    }

    function goTo(index) {
        const step = trail[index];
        if (!step) return;

        cursor = index;
        document.getElementById(step.id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        render();
    }

    function render() {
        if (dismissed || trail.length < 2) {
            panel.hidden = true;
            // Leave nothing behind the curtain: a hidden panel still holding the previous
            // thread's steps is stale markup a screen reader can still walk into.
            list.replaceChildren();
            return;
        }

        panel.hidden = false;
        backBtn.disabled = cursor <= 0;
        forwardBtn.disabled = cursor >= trail.length - 1;
        counter.textContent = `${cursor + 1}/${trail.length}`;

        list.replaceChildren(...trail.map((step, index) => {
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'block w-full text-left px-2 py-1 rounded truncate transition '
                + (index === cursor
                    ? 'bg-purple-600/30 text-white'
                    : 'text-gray-400 hover:text-white hover:bg-gray-700/60');
            item.textContent = step.label;
            item.addEventListener('click', () => goTo(index));
            return item;
        }));

        list.children[cursor]?.scrollIntoView({ block: 'nearest' });
    }

    config.root.addEventListener('click', (event) => {
        const link = event.target.closest('a[href^="#"]');
        if (!link) return;

        const id = link.getAttribute('href').slice(1);
        if (!id || !document.getElementById(id)) return;

        // Where the reader actually is, which is not necessarily where the trail last left them
        const origin = currentSection();
        if (origin && origin !== id && trail[cursor]?.id !== origin) {
            if (cursor < trail.length - 1) trail.splice(cursor + 1);
            trail.push({ id: origin, label: labelFor(origin) });
            cursor = trail.length - 1;
        }

        push(id);
    });

    // A hash link that lives OUTSIDE the tracked content is the menu (or anything playing its
    // part): the reader is deliberately going somewhere, so the previous thread is over.
    document.addEventListener('click', (event) => {
        const link = event.target.closest('a[href^="#"]');
        if (!link || config.root.contains(link) || panel.contains(link)) return;

        trail.length = 0;
        cursor = -1;
        render();
    });

    backBtn.addEventListener('click', () => goTo(cursor - 1));
    forwardBtn.addEventListener('click', () => goTo(cursor + 1));
    closeBtn.addEventListener('click', () => { dismissed = true; render(); });

    document.body.appendChild(panel);
    render();

    return { push, goTo, destroy: () => panel.remove() };
}

function buildPanel() {
    const panel = document.createElement('aside');
    panel.hidden = true;
    // Hidden on narrow screens: a fixed box a quarter of the width wide would sit on the text
    // it is supposed to help read.
    panel.className = 'hidden sm:block fixed bottom-4 right-4 z-40 w-56 bg-gray-800/95 '
        + 'border border-gray-700 rounded-lg shadow-xl backdrop-blur text-sm';
    panel.innerHTML = `
        <div class="flex items-center gap-1 px-2 py-1.5 border-b border-gray-700">
            <button type="button" data-history-back
                class="px-2 py-1 rounded text-gray-300 hover:bg-gray-700 disabled:opacity-30 disabled:hover:bg-transparent">
                <i class="fas fa-arrow-left"></i>
            </button>
            <button type="button" data-history-forward
                class="px-2 py-1 rounded text-gray-300 hover:bg-gray-700 disabled:opacity-30 disabled:hover:bg-transparent">
                <i class="fas fa-arrow-right"></i>
            </button>
            <span class="ml-auto text-xs text-gray-500" data-history-count></span>
            <button type="button" data-history-close
                class="px-1.5 py-1 rounded text-gray-500 hover:text-gray-200 hover:bg-gray-700">
                <i class="fas fa-xmark"></i>
            </button>
        </div>
        <div class="max-h-56 overflow-y-auto p-1 space-y-0.5" data-history-list></div>
    `;
    return panel;
}
