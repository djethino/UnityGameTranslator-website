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

// Organic animated background — 5 independent blob layers, scroll-reactive.
//
// Each blob is a real <div> (one radial gradient per div, animated independently).
// Splitting the gradients into per-blob layers avoids a Firefox 150 bug where
// transform animation on a single element with stacked radial gradients caused
// visible flicker. It also gives a more organic "lava lamp / plasma" motion
// since each blob has its own phase, speed, and amplitude.
(function() {
    const body = document.body;
    if (!body.classList.contains('animated-bg')) return;

    // Gradient definitions, mirroring the original CSS radial-gradient() syntax.
    // Each blob becomes a single-gradient bitmap rasterized once at load via
    // canvas.toDataURL — Firefox 150 has a bug where animated radial-gradient
    // CSS gets periodically re-rasterized (~25s cycles) and visibly flickers.
    // A bitmap is composed by the GPU as a static texture and never triggers
    // that path. Visually identical to a CSS radial-gradient at this scale.
    const gradients = [
        // cx, cy: center % | rx, ry: ellipse half-axes % | r,g,b,a: color | t: transparent stop %
        // 3 dark blue/indigo "depth" blobs anchor the composition,
        // 2 accent blobs (mauve top-right, glacial cyan bottom-left) bring colour breath.
        { cx: 20, cy: 40, rx: 40, ry: 25,   r:  15, g:  52, b:  96, a: 0.5,  t: 0.6  }, // navy
        { cx: 80, cy: 20, rx: 30, ry: 20,   r: 140, g: 100, b: 180, a: 0.28, t: 0.5  }, // soft mauve accent
        { cx: 60, cy: 80, rx: 25, ry: 30,   r:  22, g:  33, b:  62, a: 0.5,  t: 0.55 }, // deep blue-grey
        { cx: 75, cy: 60, rx: 35, ry: 22.5, r:  26, g:  26, b:  46, a: 0.6,  t: 0.55 }, // near-black blue
        { cx: 30, cy: 70, rx: 27.5, ry: 25, r:  80, g: 160, b: 200, a: 0.28, t: 0.5  }, // glacial cyan accent
    ];

    function rasterizeGradient(g) {
        // 256×256 is plenty: gradients are smooth, will be stretched to ~1900×1500
        // via background-size: 100% 100%. Imperceptible blur, ~256 KB per image.
        const SIZE = 256;
        const canvas = document.createElement('canvas');
        canvas.width = canvas.height = SIZE;
        const ctx = canvas.getContext('2d');

        const cx = g.cx * SIZE / 100;
        const cy = g.cy * SIZE / 100;
        const rx = g.rx * SIZE / 100;
        const ry = g.ry * SIZE / 100;
        const rmax = Math.max(rx, ry);

        // Canvas createRadialGradient is circular only. To draw an ellipse we
        // scale the context to deform the circle into the desired ellipse,
        // then fill enough area to cover the unscaled canvas.
        ctx.translate(cx, cy);
        ctx.scale(rx / rmax, ry / rmax);

        const grad = ctx.createRadialGradient(0, 0, 0, 0, 0, rmax);
        const c = `${g.r},${g.g},${g.b}`;
        grad.addColorStop(0, `rgba(${c},${g.a})`);
        grad.addColorStop(g.t, `rgba(${c},0)`);
        ctx.fillStyle = grad;

        // After the scale, fillRect coordinates are in scaled space.
        // Cover the whole original canvas: shifts back by -cx,-cy and the
        // size scales by (rmax/rx, rmax/ry).
        ctx.fillRect(-cx * rmax / rx, -cy * rmax / ry,
                     SIZE * rmax / rx, SIZE * rmax / ry);

        return canvas.toDataURL('image/png');
    }

    // Inject the 5 blob divs at the top of the body so they're behind everything
    // (z-index: -1 in CSS already keeps them under the content).
    const blobs = [];
    for (let i = 1; i <= 5; i++) {
        const el = document.createElement('div');
        el.className = `bg-blob bg-blob-${i}`;
        el.setAttribute('aria-hidden', 'true');
        el.style.backgroundImage = `url(${rasterizeGradient(gradients[i - 1])})`;
        body.insertBefore(el, body.firstChild);
        blobs.push(el);
    }

    // Per-blob animation parameters. Each blob superposes 3 sine waves at
    // irrational frequency ratios (φ, √2, √3) so its path never closes into
    // a Lissajous loop — the eye can't recognize a recurring pattern, the
    // motion stays "alive" indefinitely. Each blob also has its own phase
    // offsets so they never sync up either.
    const PHI = 1.6180339887;
    const SQRT2 = 1.4142135624;
    const SQRT3 = 1.7320508076;
    const config = [
        // Blobs 1-3: faster/brighter layer
        { speed: 0.50, ampX: 5, ampY: 3.5, baseO: 0.90, ampO: 0.10,
          fxa: 1.0,        fxb: 1.3 * PHI,   fxc: 0.71 * SQRT2,
          fya: 0.8 * PHI,  fyb: 0.5 * SQRT3, fyc: 1.1,
          foa: 0.4 * SQRT2,
          phaseX: 0.00, phaseY: 0.00, phaseO: 0.00 },
        { speed: 0.50, ampX: 5, ampY: 3.5, baseO: 0.90, ampO: 0.10,
          fxa: 0.9 * SQRT3, fxb: 1.2,         fxc: 0.65 * PHI,
          fya: 0.7,         fyb: 0.55 * PHI,  fyc: 1.0 * SQRT2,
          foa: 0.35 * PHI,
          phaseX: 1.73, phaseY: 0.91, phaseO: 1.34 },
        { speed: 0.50, ampX: 5, ampY: 3.5, baseO: 0.90, ampO: 0.10,
          fxa: 1.1 * SQRT2, fxb: 0.85 * PHI,  fxc: 0.58,
          fya: 0.95 * PHI,  fyb: 0.6,         fyc: 1.2 * SQRT3,
          foa: 0.45,
          phaseX: 3.41, phaseY: 1.78, phaseO: 2.61 },
        // Blobs 4-5: slower/dimmer layer
        { speed: 0.35, ampX: 6, ampY: 4.5, baseO: 0.75, ampO: 0.10,
          fxa: 0.7 * PHI,   fxb: 1.05,        fxc: 0.78 * SQRT3,
          fya: 0.9,         fyb: 0.55 * SQRT2, fyc: 1.15 * PHI,
          foa: 0.35 * SQRT3,
          phaseX: 0.51, phaseY: 0.32, phaseO: 0.73 },
        { speed: 0.35, ampX: 6, ampY: 4.5, baseO: 0.75, ampO: 0.10,
          fxa: 1.0 * PHI,   fxb: 0.65 * SQRT2, fxc: 0.83,
          fya: 0.75 * SQRT3, fyb: 1.1,         fyc: 0.5 * PHI,
          foa: 0.4 * PHI,
          phaseX: 2.21, phaseY: 1.23, phaseO: 2.05 },
    ];

    // Animation state
    let time = 0;
    let velocity = 0;
    let targetVelocity = 0;
    let lastScrollY = window.scrollY;
    let lastScrollTime = performance.now();
    let lastFrameTime = performance.now();
    let isScrolling = false;
    let scrollTimeout = null;
    let isPaused = false;

    // Time-rate config (per-second values, scaled by deltaTime in animate())
    // baseSpeed feeds the per-blob `time` rate. A blob at speed=0.5 with
    // baseSpeed=0.3 makes its fastest sine component (sin(t*1.3)) cycle
    // every ~32s — perceptible motion without being agitated.
    const baseSpeed = 0.3;           // idle animation rate
    const scrollMultiplier = 5;      // how much scroll velocity feeds animation time
    const velocityDecay = 5;         // higher = faster return to baseline after scroll
    const velocitySmoothness = 8;    // higher = quicker response when scroll velocity changes

    function updateBlobs() {
        for (let i = 0; i < blobs.length; i++) {
            const c = config[i];
            const t = time * c.speed;
            // 3 superposed sines per axis with irrational frequency ratios:
            // the path never closes into a periodic loop, so the motion never
            // looks "looped". Weights 0.45/0.35/0.20 sum to 1, total amplitude
            // stays at ampX/ampY.
            const x = Math.sin(t * c.fxa + c.phaseX) * c.ampX * 0.45
                    + Math.sin(t * c.fxb + c.phaseY) * c.ampX * 0.35
                    + Math.sin(t * c.fxc + c.phaseO) * c.ampX * 0.20;
            const y = Math.cos(t * c.fya + c.phaseY) * c.ampY * 0.45
                    + Math.sin(t * c.fyb + c.phaseX) * c.ampY * 0.35
                    + Math.cos(t * c.fyc + c.phaseO) * c.ampY * 0.20;
            const o = c.baseO + Math.sin(t * c.foa + c.phaseO) * c.ampO;
            const s = blobs[i].style;
            s.setProperty('--x', x + '%');
            s.setProperty('--y', y + '%');
            s.setProperty('--o', o);
        }
    }

    function onScroll() {
        const now = performance.now();
        const currentScrollY = window.scrollY;
        const deltaY = currentScrollY - lastScrollY;
        const deltaTime = now - lastScrollTime;

        if (deltaTime > 0) {
            // Exponential response — fast scroll feels much more reactive than slow scroll
            const scrollSpeed = deltaY / deltaTime;
            targetVelocity = Math.sign(scrollSpeed) * Math.pow(Math.abs(scrollSpeed), 1.3) * scrollMultiplier;
        }

        lastScrollY = currentScrollY;
        lastScrollTime = now;
        isScrolling = true;

        clearTimeout(scrollTimeout);
        scrollTimeout = setTimeout(() => {
            isScrolling = false;
            targetVelocity = 0;
        }, 100);
    }

    function animate(currentTime) {
        const deltaTime = Math.min((currentTime - lastFrameTime) / 1000, 0.1); // cap at 100ms to absorb tab-switch jumps
        lastFrameTime = currentTime;

        if (isPaused) {
            requestAnimationFrame(animate);
            return;
        }

        // Frame-rate-independent velocity smoothing & decay
        const lerpFactor = 1 - Math.exp(-velocitySmoothness * deltaTime);
        velocity += (targetVelocity - velocity) * lerpFactor;
        if (!isScrolling) {
            velocity *= Math.exp(-velocityDecay * deltaTime);
        }

        time += (baseSpeed + velocity) * deltaTime;

        updateBlobs();
        requestAnimationFrame(animate);
    }

    // Pause when tab is hidden so we don't spin the GPU for nothing.
    document.addEventListener('visibilitychange', () => {
        isPaused = document.hidden;
        if (!isPaused) {
            lastFrameTime = performance.now();
            velocity = 0;
            targetVelocity = 0;
        }
    });

    window.addEventListener('scroll', onScroll, { passive: true });
    requestAnimationFrame(animate);
})();

// F: Stats counter ramping — any element with [data-counter] gets its number
// animated from 0 to its final value on first viewport entry. Source value
// is parsed from data-counter (preferred) or from the existing textContent.
// Original formatting (commas/spaces) is preserved if Intl.NumberFormat-derived.
(function() {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

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

// G: Random ambient glitch ping — every 30-90 seconds, a random eligible
// element (image, badge, heading, vignette) briefly glitches via the
// .glitching class. Subtle enough to not annoy, frequent enough to give the
// page a feeling of "being alive". Skipped under reduced-motion.
(function() {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    function pickTargets() {
        // Anything visually substantial that wouldn't break if it briefly shakes.
        return Array.from(document.querySelectorAll(
            'img.game-card-image, .game-card img, .badge, ' +
            'h1.glitch-text, h2.glitch-text, ' +
            '.translation-card img, [data-glitch-target]'
        )).filter(el => {
            const rect = el.getBoundingClientRect();
            // Only target something currently in the viewport
            return rect.top < window.innerHeight && rect.bottom > 0
                && rect.left < window.innerWidth && rect.right > 0;
        });
    }

    function fireGlitchPing() {
        const targets = pickTargets();
        if (targets.length) {
            const t = targets[Math.floor(Math.random() * targets.length)];
            t.classList.add('glitching');
            setTimeout(() => t.classList.remove('glitching'), 320);
            return t;
        }
        return null;
    }

    function pingRandom() {
        fireGlitchPing();
        // Schedule next ping in 30-90s
        setTimeout(pingRandom, 30000 + Math.random() * 60000);
    }

    // Don't fire too soon after page load
    setTimeout(pingRandom, 8000 + Math.random() * 12000);

    // Dev/QA helper: window.testGlitch() in the console fires an immediate
    // glitch on a random visible target and returns it. Lets you verify the
    // effect without waiting for the random schedule.
    window.testGlitch = fireGlitchPing;
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

    const form = choice.closest('form[data-auto-submit]');
    if (!form) return;

    const field = choice.closest('[data-language-picker]')?.querySelector('[data-language-field]');
    if (field) field.value = choice.dataset.value ?? '';

    form.submit();
});

// A submit button that only existed to apply those fields has nothing left to do. It is hidden
// HERE, by the very code that makes it redundant: the two can never fall out of step, and a
// visitor without JavaScript keeps the only control that works for them.
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('form[data-auto-submit] [data-hide-when-auto]')
        .forEach((button) => button.remove());
});
