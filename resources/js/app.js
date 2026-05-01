import './bootstrap';

// Alpine.js (CSP build — no eval/Function, compatible with strict CSP)
import Alpine from '@alpinejs/csp';

// Alpine components
import mergeTable from './components/merge-table.js';
Alpine.data('mergeTable', mergeTable);

window.Alpine = Alpine;
Alpine.start();

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
