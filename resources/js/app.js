import './bootstrap';

// Alpine.js (CSP build — no eval/Function, compatible with strict CSP)
import Alpine from '@alpinejs/csp';
import mediumZoom from 'medium-zoom';

// Alpine components
import mergeTable from './components/merge-table.js';
Alpine.data('mergeTable', mergeTable);

// Locally-generated avatars (DiceBear "thumbs", CC0): the SVG is built in
// the browser from a seed — no upload, no external request, no PII.
// Placeholders: <span data-dicebear-seed="..." data-dicebear-size="32">
import { createAvatar } from '@dicebear/core';
import { thumbs } from '@dicebear/collection';

function hydrateAvatars(root = document) {
    root.querySelectorAll('[data-dicebear-seed]').forEach(el => {
        if (el.dataset.dicebearDone) return;
        el.dataset.dicebearDone = '1';
        const size = parseInt(el.dataset.dicebearSize || '32', 10);
        const avatar = createAvatar(thumbs, {
            seed: el.dataset.dicebearSeed,
            size,
            radius: 50,
        });
        el.innerHTML = avatar.toString();
    });
}
hydrateAvatars();
window.UGT_hydrateAvatars = hydrateAvatars;

// Site-wide announcement banner: dismissible per announcement id,
// remembered in localStorage (works for guests too).
Alpine.data('announceBanner', () => ({
    visible: false,

    init() {
        const id = this.$el.dataset.bannerId;
        this.visible = id && localStorage.getItem('ugt_banner_dismissed') !== id;
    },

    dismiss() {
        localStorage.setItem('ugt_banner_dismissed', this.$el.dataset.bannerId);
        this.visible = false;
    },
}));

// Header notification bell: unread badge, light poll (count only, 60s,
// paused while the tab is hidden). URLs come from data-attributes.
Alpine.data('notifBell', () => ({
    count: 0,
    _timer: null,

    init() {
        this.count = parseInt(this.$el.dataset.initialCount || '0', 10) || 0;
        const url = this.$el.dataset.countUrl;
        if (!url) return;

        const poll = async () => {
            if (document.hidden) return;
            try {
                const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
                if (res.ok) {
                    const data = await res.json();
                    this.count = data.unread ?? 0;
                }
            } catch { /* transient network error: keep the last known count */ }
        };
        this._timer = setInterval(poll, 60000);
        document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
    },

    get badge() {
        return this.count > 99 ? '99+' : String(this.count);
    },

    get hasUnread() {
        return this.count > 0;
    },
}));

// Shared editor core for the client-side translation editors (merge-preview,
// edit-session). Their Alpine components stay inline in the Blade views
// (they need @js() strings and route() URLs), so the factory is exposed
// globally for the nonce'd inline scripts.
import { composeEditor, normalizeLineEndings } from './components/translation-editor.js';
import { createLiveSync } from './components/live-sync.js';
import { createSectionHistory } from './section-history.js';
import { createSectionSpy } from './section-spy.js';
import { createViewer } from './components/translation-viewer.js';
window.UGT = { composeEditor, normalizeLineEndings, createLiveSync, createViewer };

// Flowing text or line breaks. The three editors get it by composing the editor
// core; this registration is for any other screen that lists translation lines
// (the admin inspection view) so the whole site answers to ONE preference.
import { editorTextMode } from './components/editor-text-mode.js';
Alpine.data('editorTextMode', () => ({
    ...editorTextMode(),
    init() { this.initTextMode(); },
}));

// x-html is prohibited by the Alpine CSP build. The editors need to inject
// their own search-highlight markup, so x-safe-html provides the same
// semantics restricted to OUR trusted helpers: translation-editor.js
// escapes every character of the content and only adds <mark> tags.
Alpine.directive('safe-html', (el, { expression }, { evaluateLater, effect }) => {
    const getHtml = evaluateLater(expression);
    effect(() => getHtml(html => { el.innerHTML = html; }));
});

window.Alpine = Alpine;
Alpine.start();

// Image zoom for documentation screenshots. Inline onclick handlers are dead
// under our CSP (nonce'd script-src makes browsers ignore 'unsafe-inline'),
// so the zoom must be wired from the bundle. medium-zoom: click zooms the
// image for real, scroll/Escape/click puts it back.
mediumZoom('[data-zoomable]', {
    background: 'rgba(3, 7, 18, 0.92)',
    margin: 24,
});

// A trail of the sections the reader has jumped through, on any page that asks for one by
// carrying [data-section-history]. Loaded here rather than per page: it is one small module and
// the bundle is already shared. It shows nothing until a cross-link is actually followed.
// Sections of the table of contents that carry sub-entries fold away.
//
// Eleven of them do, holding about fifty subjects. Unfolded all at once that is a menu twice the
// height of the screen — you would scroll the table of contents to find out where you are, which
// is the thing it exists to spare you. So they ship folded (the Blade writes `is-collapsed`, no
// flash of open menu on load) and THE SECTION BEING READ OPENS ITSELF. The reader never has to
// choose between seeing the whole map and seeing the detail of where they stand.
//
// Nothing here knows which page or which section: it acts on whatever carries the attribute, so a
// section gains a chevron by gaining sub-entries and this file never has to hear about it.
const collapsibles = [...document.querySelectorAll('[data-nav-collapsible]')];

const setNavOpen = (group, open) => {
    group.querySelector('.docs-nav-toggle')?.setAttribute('aria-expanded', String(open));
    group.querySelector('.docs-nav-subs')?.classList.toggle('is-collapsed', !open);
};

const navSectionOf = (group) =>
    group.querySelector('.docs-nav-item')?.getAttribute('href')?.slice(1);

collapsibles.forEach(group => {
    const toggle = group.querySelector('.docs-nav-toggle');
    if (!toggle || !group.querySelector('.docs-nav-subs')) return;

    toggle.addEventListener('click', () => {
        setNavOpen(group, toggle.getAttribute('aria-expanded') !== 'true');
        // Decided by hand: scrolling inside the current section must not undo it. Cleared on the
        // way out, below — a preference about one section has no business outliving the visit.
        group.dataset.navUser = '';
    });
});

// A trail of the sections the reader has jumped through, on any page that asks for one by
// carrying [data-section-history]. Loaded here rather than per page: it is one small module and
// the bundle is already shared. It shows nothing until a cross-link is actually followed.
const historyRoot = document.querySelector('[data-section-history]');
if (historyRoot) {
    createSectionHistory({ root: historyRoot });
    // The table of contents finally says where you are. Same page, same position measurement —
    // they share section-position.js so the trail and the menu can never disagree about it.
    // ⚠ `.docs-nav-sub` is in the link list AND `[data-nav-anchor]` in the anchor list, or the menu
    // lists sub-entries that can never light up. The trail above keeps the default selector: it
    // records sections, and recording sub-headings would make it far noisier for no gain.
    let lastNavSection = null;

    createSectionSpy({
        root: historyRoot,
        linkSelector: '.docs-nav-item, .docs-nav-sub',
        anchorSelector: 'section[id], [data-nav-anchor]',
        // The trail is deepest-first, so the section is its last element.
        onCurrent: (trail) => {
            const section = trail[trail.length - 1] ?? null;

            // ⚠ Only on LEAVING a section, never on moving between its sub-parts — otherwise a
            // section folded by hand springs back open on the next scroll inside it, and the
            // chevron becomes a control that does nothing where you happen to be standing.
            if (section !== lastNavSection) {
                lastNavSection = section;
                collapsibles.forEach(group => { delete group.dataset.navUser; });
            }

            collapsibles.forEach(group => {
                if ('navUser' in group.dataset) return;
                setNavOpen(group, trail.includes(navSectionOf(group)));
            });
        },
    });
}

// Organic animated background, the ambient glitch, and the one that swaps a word into another of
// the site's twenty languages. All three live under ./ambient and ./glitch — see the header of
// ambient/engine.js for why this became a canvas, and glitch/targets.js for why nothing is aimed
// at a list of CSS classes any more.
import { startAmbient } from './ambient/index.js';
startAmbient();

// F: Stats counter ramping — any element with [data-counter] gets its number
// animated from 0 to its final value on first viewport entry. Source value
// is parsed from data-counter (preferred) or from the existing textContent.
// Original formatting (commas/spaces) is preserved if Intl.NumberFormat-derived.
//
// ⚠ The reduced-motion answer comes from ambient/motion.js, not from a media query read here. The
// site now has its own control beside the system setting, and a second reading of only one of the
// two sources is how the page ends up half-obeying a preference.
import { reducedMotion } from './ambient/motion.js';

(function() {
    if (reducedMotion()) return;

    const elements = document.querySelectorAll('[data-counter]');
    if (!elements.length) return;

    function easeOutCubic(t) { return 1 - Math.pow(1 - t, 3); }

    function animateCounter(el) {
        const raw = el.getAttribute('data-counter') || el.textContent.replace(/[^\d.-]/g, '');
        const target = parseFloat(raw);
        if (!isFinite(target)) return;
        const isInt = Number.isInteger(target);
        const duration = Math.min(1200, 600 + Math.log10(Math.max(target, 1)) * 200);
        const start = performance.now();
        const formatter = new Intl.NumberFormat();

        function tick(now) {
            const t = Math.min(1, (now - start) / duration);
            const v = target * easeOutCubic(t);
            el.textContent = formatter.format(isInt ? Math.round(v) : Math.round(v * 10) / 10);
            if (t < 1) requestAnimationFrame(tick);
        }
        requestAnimationFrame(tick);
    }

    const io = new IntersectionObserver((entries) => {
        entries.forEach(e => {
            if (e.isIntersecting) {
                animateCounter(e.target);
                io.unobserve(e.target);
            }
        });
    }, { threshold: 0.5 });

    elements.forEach(el => io.observe(el));
})();


/**
 * Confirmation before a destructive submit, without inline handlers.
 *
 * `onsubmit="return confirm(...)"` looks harmless but never runs on this site:
 * the CSP carries a nonce, and a nonce makes the browser ignore 'unsafe-inline'
 * entirely. Every inline handler was therefore blocked as script-src-attr —
 * silently, since a blocked handler produces no visible failure. "End session"
 * simply did nothing, with no request ever leaving the page.
 *
 * Delegated on the document so it also covers markup added after load, and put
 * here rather than in a per-view <script nonce>: the same five lines had been
 * copied into three templates already.
 *
 * Usage: <form data-confirm="{{ __('...') }}">
 */
document.addEventListener('submit', (event) => {
    const form = event.target.closest('form[data-confirm]');
    if (!form) return;

    const message = form.getAttribute('data-confirm');
    if (message && !window.confirm(message)) {
        event.preventDefault();
    }
}, true);

/**
 * ⚠ A `[data-fill]` chip handler lived here: click a name, it went into a field. Its only caller
 * was the linking page's "Which device is this?" box, and that field is gone — the machine says
 * which it is on its own, so nobody is asked to name one while linking. Naming happens on the
 * Linked devices screen, where the thing being named is on screen.
 */

/**
 * Forms that apply on change.
 *
 * A dropdown or a checkbox should give its result in one gesture — that is how the editors'
 * filters already behave, and asking for a second click to confirm a filter protects nothing.
 * The submit button stays for text fields (reloading on every keystroke would be absurd) and
 * for anyone without JavaScript.
 *
 * Delegated from the document rather than bound inline: the site's CSP forbids inline handlers,
 * and this works whatever the framework build does with expressions.
 */
document.addEventListener('change', (event) => {
    const field = event.target;
    if (!field || field.hasAttribute('data-no-auto-submit')) return;

    const form = field.closest('form[data-auto-submit]');
    if (form) form.submit();
});

/**
 * The same gesture for the language picker, which is not a field.
 *
 * 🔴 It writes into a hidden input through Alpine, and a hidden input assigned in code fires no
 * `change` — so the listener above never hears it and a filter bar built on it would look right
 * and filter nothing.
 *
 * ⚠ Done HERE rather than in the component, and that is the whole point: this site runs
 * @alpinejs/csp, whose parser refuses anything beyond a property access or a bare call — a helper
 * method added to x-data to dispatch the event is not rejected, it is evaluated to NOTHING, which
 * leaves `open` undefined and draws every picker on the page already open. Plain JS in a bundled
 * file has no such limit.
 *
 * ⚠ And the value is written HERE, from the entry that was clicked, rather than read back from the
 * picker. Alpine flushes `:value` on its own schedule; waiting a frame for it posted an empty
 * filter — measured, not feared. The clicked entry already carries the answer, so there is nothing
 * to wait for and no scheduler to guess at.
 */
document.addEventListener('click', (event) => {
    const choice = event.target.closest('[data-language-choice]');
    if (!choice) return;

    const picker = choice.closest('[data-language-picker]');
    if (!picker) return;

    // 🔴 Writing the choice down and ACTING on it are two different things, and conflating them
    // broke both language pickers in the profile for as long as they existed. The submit was
    // guarded by `form[data-auto-submit]`, and the write sat behind that guard — so on a form with
    // a Save button the value was simply never recorded. The dropdown closed, the page kept the
    // old language, and nothing anywhere said why.
    const field = picker.querySelector('[data-language-field]');
    if (field) field.value = choice.dataset.value ?? '';

    // ⚠ The label and the flag are moved here too. Alpine cannot: its CSP build silently declines
    // an assignment whose right-hand side is a chain of accesses, which is exactly what reading
    // `$el.dataset.label` is. And a flag left behind would name one language beside the name of
    // another — worse than showing none.
    const label = picker.querySelector('[data-language-label]');
    if (label) label.textContent = choice.dataset.label ?? '';

    const flag = picker.querySelector('[data-language-flag]');
    const chosenFlag = choice.querySelector('[data-language-choice-flag]');
    if (flag && chosenFlag) flag.innerHTML = chosenFlag.innerHTML;

    // Only now, and only where the form asks for it: a filter bar applies at once, a settings form
    // waits for its Save button.
    choice.closest('form[data-auto-submit]')?.submit();
});

// A submit button that only existed to apply those fields has nothing left to do. It is hidden
// HERE, by the very code that makes it redundant: the two can never fall out of step, and a
// visitor without JavaScript keeps the only control that works for them.
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('form[data-auto-submit] [data-hide-when-auto]')
        .forEach((button) => button.remove());
});
