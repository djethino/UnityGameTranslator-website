/**
 * The loop, the frame budget, and the bridge between choreography and population.
 *
 * A pattern says where five CENTRES should be. Each centre carries a cloud of several hundred
 * points that chase it at their own individual rates. Nothing between the two needs to describe a
 * shape, and nothing does.
 *
 * ── Where the trail went ───────────────────────────────────────────────────────────────────────
 * 🟢 There is no accumulation buffer any more, and no `inkFade` for a pattern to set. A cloud whose
 * centre moves fast already leaves its slower points behind — the tail is the lag, it is physical,
 * it is free, and it obeys the rule we wanted without anyone enforcing it: long when a cloud
 * crosses the view, absent when it charges the camera and barely moves on screen. Writing a letter
 * no longer needs one either, because a cloud can simply TAKE the shape of the letter.
 *
 * ── The budget ─────────────────────────────────────────────────────────────────────────────────
 * Two dials, in this order: the population is thinned first (a sparser cloud still looks like the
 * same thing), and only then is the buffer resolution dropped. Measured from the frame interval,
 * with quick descent and slow recovery so one hiccup cannot start an oscillation.
 */

import { Bob, Z_NEAR, Z_FAR } from './bob.js';
import { Cloud } from './cloud.js';
import { createRenderer } from './gl/renderer.js';
import { backgroundSpeed, level, onMotionChange } from './motion.js';

export const CLOUD_COUNT = 5;

/**
 * How many points a cloud can hold, and how many it uses by default.
 *
 * The substance comes from the COUNT, not from the size of a grain. A first version drew 700 points
 * at 3 % of the buffer height each — around 37 px on screen — which read as a handful of large discs
 * rather than as a cloud.
 *
 * These two numbers are tied together and must be changed together: coverage is N x pi x r^2 over
 * the area of the cloud disc. 700 points at 0.030 gave 7.4x overlap; 2200 at 0.016 give 6.6x for
 * half the grain. Shrinking the points WITHOUT raising the count would have thinned the cloud to
 * 3.2x, which is a different bug wearing the same fix.
 */
const CLOUD_CAPACITY = 3000;
const PER_CLOUD = 2200;

/** A cloud's resting radius, in field units — one unit is half the viewport height. */
const CLOUD_RADIUS = 0.60;

/** A point's diameter at the reference plane, as a fraction of the buffer height. */
const POINT_SIZE = 0.012;

/** How opaque one point is. Density does the rest: these are read in their thousands. */
const POINT_ALPHA = 0.20;

/**
 * How much halo surrounds each point's core.
 *
 * 🔴 This is the dial that makes a distant cloud visible, and it took two wrong answers to find.
 * Fading points with depth (opacity) made them disappear rather than recede, because the size was
 * already shrinking at the same rate. Spreading their glow instead keeps the same amount of light
 * but over more pixels, which is exactly what a light at a distance does.
 */
const POINT_GLOW = 20.55;

/**
 * The wash: the light the clouds cast on the background, as opposed to the clouds themselves.
 *
 * Drawn in its own pass, additively, into a buffer a fifth the size — so it is blurred by the
 * upscale alone and costs almost nothing. Additive means the COLOURS MIX there, which is the one
 * place they are supposed to: two coloured lamps blend on a wall while staying two lamps.
 *
 * SPREAD is how much wider a point glows than it measures, ALPHA how much light one point
 * contributes (they accumulate in their thousands, so this is small), and INTENSITY how strongly
 * the whole wash lands on the frame.
 */
// ⚠ Deliberately strong for now — set high so the effect can be judged at all, to be brought back
// down once its level has been chosen by looking at it. See window.ambient.set('wash', ...).
const WASH_SPREAD = 20.5;
const WASH_ALPHA = 0.05;
const WASH_INTENSITY = 0.5;

/**
 * The five allegiances. The hues carry over from the background this replaces — three blues
 * anchoring the composition, mauve and glacial cyan for colour breath — but they are now the
 * colour of a body rather than of a veil, and they are never averaged with one another.
 */
const PALETTE = [
    [0.30, 0.48, 0.86], // navy
    [0.66, 0.44, 0.88], // mauve
    [0.28, 0.40, 0.72], // blue-grey
    [0.36, 0.34, 0.70], // indigo
    [0.34, 0.72, 0.88], // glacial cyan
];

const SCALES = [0.62, 0.5, 0.4, 0.3];
const KEEPS = [1, 0.7, 0.45];
const SLOW_MS = 20, FAST_MS = 14, SLOW_SAMPLES = 45, FAST_SAMPLES = 180;

const BASE_SPEED = 0.3, SCROLL_MULTIPLIER = 5, VELOCITY_DECAY = 5, VELOCITY_SMOOTHNESS = 8;
const PATTERN_WARP_DAMPING = 6, PATTERN_WARP_MAX = 4;

export function createEngine() {
    const canvas = document.createElement('canvas');
    canvas.className = 'ambient-canvas';
    canvas.setAttribute('aria-hidden', 'true');
    document.body.insertBefore(canvas, document.body.firstChild);

    const capacity = CLOUD_COUNT * CLOUD_CAPACITY;
    const renderer = createRenderer(canvas, capacity);
    if (!renderer) {
        // No WebGL on this machine. CSS paints a still gradient instead — see app.css. There is
        // deliberately no second renderer: a fallback engine nobody runs is a fallback that breaks.
        canvas.remove();
        document.body.classList.add('ambient-unavailable');
        return null;
    }

    const bobs = Array.from({ length: CLOUD_COUNT }, (_, i) => new Bob(i));
    const clouds = bobs.map((_, i) => new Cloud(i, CLOUD_CAPACITY, PALETTE[i]));

    const colors = new Float32Array(capacity * 3);
    const sizes = new Float32Array(capacity);
    const points = new Float32Array(capacity * 4);

    /**
     * Colours and sizes are uploaded once — but they sit in the SAME packed order the position
     * buffer uses, so changing how many points a cloud contributes has to rebuild them.
     * Get this wrong and one cloud's positions are drawn in another cloud's colour.
     */
    function rebuildStatic(perCloud) {
        for (let c = 0, k = 0; c < CLOUD_COUNT; c++) {
            for (let i = 0; i < perCloud; i++, k++) {
                // A little colour and size scatter within a cloud. Perfectly uniform points read as
                // a material; slightly varied ones read as a substance.
                const v = 0.88 + Math.random() * 0.24;
                colors[k * 3] = Math.min(1, PALETTE[c][0] * v);
                colors[k * 3 + 1] = Math.min(1, PALETTE[c][1] * v);
                colors[k * 3 + 2] = Math.min(1, PALETTE[c][2] * v);
                sizes[k] = 0.78 + Math.random() * 0.5;
            }
        }
        renderer.setStatic(colors, sizes);
    }

    let w = 0, h = 0, aspect = 1;
    let scaleIndex = 0, entryScale = 0, keepIndex = 0;
    let lastW = 0, lastH = 0;

    let time = 0, patternTime = 0;
    let velocity = 0, targetVelocity = 0;
    let lastScrollY = window.scrollY, lastScrollTime = performance.now();
    let lastFrameTime = performance.now();
    let isScrolling = false, scrollTimeout = null, paused = document.hidden;
    let slowCount = 0, fastCount = 0;

    // How fast this universe runs, and whether it runs at all. 0 means the visitor asked for no
    // background; `calm` keeps it drifting but takes the abrupt patterns out of the rotation.
    let speed = backgroundSpeed();
    let calm = level('background') === 'slow';
    let cleared = false;
    let warp = 0;   // set by the conductor, blended across pattern changes

    // Live tuning, exposed the same way `window.testGlitch` is. These are the numbers that can only
    // be judged by looking, and rebuilding the bundle to move one of them is a waste of everybody's
    // time — see `window.ambient` at the bottom of this file.
    const tune = {
        alpha: POINT_ALPHA, glow: POINT_GLOW, pointSize: POINT_SIZE,
        radius: CLOUD_RADIUS, perCloud: PER_CLOUD,
        washSpread: WASH_SPREAD, washAlpha: WASH_ALPHA, wash: WASH_INTENSITY,
    };
    rebuildStatic(tune.perCloud);

    function resize() {
        const cw = window.innerWidth, ch = window.innerHeight;
        // Showing and hiding a mobile address bar fires resize continuously while scrolling.
        // Reallocating for a change nobody can see on a blurred field is pure jank.
        if (lastW === cw && lastH && Math.abs(ch - lastH) / lastH < 0.2) return;
        lastW = cw; lastH = ch;

        const s = SCALES[scaleIndex];
        w = canvas.width = Math.max(2, Math.round(cw * s));
        h = canvas.height = Math.max(2, Math.round(ch * s));
        aspect = w / h;
        renderer.resize(w, h);
    }

    function budget(dtMs) {
        if (dtMs > SLOW_MS) { slowCount++; fastCount = 0; } else if (dtMs < FAST_MS) { fastCount++; slowCount = 0; }

        if (slowCount >= SLOW_SAMPLES) {
            slowCount = 0;
            // Thin the population before blurring the picture: half as many points still reads as
            // the same cloud, where a quarter-resolution buffer reads as a broken page.
            if (keepIndex < KEEPS.length - 1) keepIndex++;
            else if (scaleIndex < SCALES.length - 1) { scaleIndex++; lastW = 0; resize(); }
        } else if (fastCount >= FAST_SAMPLES) {
            fastCount = 0;
            if (scaleIndex > entryScale) { scaleIndex--; lastW = 0; resize(); }
            else if (keepIndex > 0) keepIndex--;
        }
    }

    function onScroll() {
        const now = performance.now();
        const y = window.scrollY;
        const dy = y - lastScrollY;
        const dt = now - lastScrollTime;
        if (dt > 0) {
            const speed = dy / dt;
            targetVelocity = Math.sign(speed) * Math.pow(Math.abs(speed), 1.3) * SCROLL_MULTIPLIER;
        }
        lastScrollY = y; lastScrollTime = now;
        isScrolling = true;
        clearTimeout(scrollTimeout);
        scrollTimeout = setTimeout(() => { isScrolling = false; targetVelocity = 0; }, 100);
    }

    let onFrame = () => [];

    /**
     * ⚠ One frame, run by hand.
     *
     * requestAnimationFrame does not fire in a tab that is not being displayed, which makes every
     * pattern in this system unobservable from an automated browser session — and a single bad
     * identifier in one of them kills the loop the first time it comes up in rotation, possibly
     * minutes later. This is how a figure gets exercised before anybody has to sit and wait for it.
     */
    function stepOnce(ms = 16) {
        // 🔴 The pause is lifted for the duration of the step, and this is the entire point of the
        // helper. `paused` follows document.hidden, which is TRUE in exactly the situation this
        // exists for — a tab driven from outside — so without this the call did nothing at all,
        // silently: the field stayed frozen, every reading came back identical, and a check for
        // "does anything jump" answered no because nothing moved. A verification that cannot fail
        // is worse than none.
        const wasPaused = paused;
        paused = false;
        const now = performance.now();
        lastFrameTime = now - ms;
        frame(now);
        paused = wasPaused;
    }

    function frame(now) {
        const wall = Math.min((now - lastFrameTime) / 1000, 0.1);
        const dtMs = now - lastFrameTime;
        lastFrameTime = now;

        if (paused || renderer.lost) { requestAnimationFrame(frame); return; }

        // Switched off. The loop stays alive so turning it back on is instant, and it costs a
        // comparison per frame — cheaper than tearing the context down and building it again.
        if (speed === 0) {
            if (!cleared) { renderer.clear(); cleared = true; }
            requestAnimationFrame(frame);
            return;
        }
        cleared = false;
        budget(dtMs);

        if (calm) {
            velocity = 0; targetVelocity = 0;
        } else {
            const lerp = 1 - Math.exp(-VELOCITY_SMOOTHNESS * wall);
            velocity += (targetVelocity - velocity) * lerp;
            if (!isScrolling) velocity *= Math.exp(-VELOCITY_DECAY * wall);
        }

        // Two time bases. The scroll warp is kept in full for the private drift, where amplitudes
        // are small and a burst reads as a shimmer; damped and capped for choreography, where the
        // undamped factor of forty would finish an eight-second figure in two hundred milliseconds.
        // The chosen speed scales BOTH clocks, so a slower background is slower in every respect —
        // its drift, its choreography and how hard the reader's scrolling pushes it.
        const driftDt = wall * speed * (1 + velocity / BASE_SPEED);
        const patternDt = wall * speed * Math.min(PATTERN_WARP_MAX, 1 + velocity / (BASE_SPEED * PATTERN_WARP_DAMPING));
        time += driftDt;
        patternTime += patternDt;

        // ⚠ The signed scroll velocity goes through as well, and it is NOT the same information
        // as the time warp it already feeds. The warp is a magnitude with no direction, so a
        // pattern cannot tell scrolling up from scrolling down with it. One that wants to be thrown
        // by the reader needs the sign.
        const targets = onFrame({ bobs, clouds, time, patternTime, dt: patternDt, wall, aspect, calm, scroll: velocity });

        let k = 0;
        let zMin = Infinity, zMax = -Infinity;
        const perCloud = tune.perCloud;

        for (let c = 0; c < CLOUD_COUNT; c++) {
            const bob = bobs[c];
            bob.follow(targets[c] || { x: bob.x, y: bob.y, z: bob.z }, time, wall);

            const cloud = clouds[c];
            // ⚠ The base spin is the engine's own — a slow turn-over so a resting cloud never
            // presents the same face for a whole pattern. `bob.twist` ADDS to it rather than
            // replacing it, so a pattern that wants a rotation gets one without having to
            // reproduce the idle behaviour it is built on.
            cloud.update(bob, tune.radius * bob.scale, wall, time, time * 0.12 + c + bob.twist,
                         bob.shearX, bob.shearY, perCloud, bob.yaw, bob.grip);

            const pos = cloud.pos;
            const gain = bob.gain;
            for (let i = 0; i < perCloud; i++, k++) {
                const j = i * 3;
                const z = pos[j + 2];
                points[k * 4] = pos[j];
                points[k * 4 + 1] = pos[j + 1];
                points[k * 4 + 2] = z < 0.12 ? 0.12 : z;  // never behind the camera
                points[k * 4 + 3] = gain;
                if (z < zMin) zMin = z;
                if (z > zMax) zMax = z;
            }
        }

        renderer.draw({
            points, count: k, aspect,
            pointScale: tune.pointSize * h,
            alpha: tune.alpha * (calm ? 0.75 : 1),
            glow: tune.glow,
            washSpread: tune.washSpread,
            washAlpha: tune.washAlpha * (calm ? 0.8 : 1),
            washIntensity: tune.wash,
            warp,
            keep: KEEPS[keepIndex],
            zNear: Math.max(0.12, zMin), zFar: Math.max(zMin + 0.01, zMax),
        });

        requestAnimationFrame(frame);
    }

    document.addEventListener('visibilitychange', () => {
        paused = document.hidden;
        if (!paused) { lastFrameTime = performance.now(); velocity = 0; targetVelocity = 0; }
    });
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', resize, { passive: true });
    onMotionChange(() => {
        speed = backgroundSpeed();
        calm = level('background') === 'slow';
    });

    return {
        bobs,
        clouds,
        get aspect() { return aspect; },
        /** A cloud's resting radius in field units. A pattern that has to match something of a
         *  known size — the width of a heading, say — needs it, because `bob.scale` is a multiplier
         *  on this and not a size in itself. It is also live-tunable, so it cannot be a constant a
         *  pattern imports. */
        get radius() { return tune.radius; },
        /** The conductor asks this to keep the abrupt patterns out of a calm rotation. */
        get reduced() { return calm; },
        setWarp(v) { warp = v; },
        start(fn) {
            onFrame = fn;
            entryScale = calm ? 2 : 0;
            scaleIndex = entryScale;
            keepIndex = calm ? 1 : 0;
            resize();
            bobs.forEach((b, i) => {
                const a = (i / CLOUD_COUNT) * Math.PI * 2;
                b.place(Math.cos(a) * 0.5, Math.sin(a) * 0.32, 1 + (i % 3) * 0.25);
                clouds[i].place(b, tune.radius);
            });
            requestAnimationFrame(frame);

            // Dev/QA helper, in the spirit of `window.testGlitch`. The three numbers below are the
            // ones that can only be settled by looking at them, so they are adjustable live rather
            // than through a rebuild.
            window.ambient = {
                get tune() { return { ...tune }; },
                set(key, v) {
                    if (!(key in tune)) return { ...tune };
                    if (key === 'perCloud') {
                        // Bounded by what the buffers were allocated for, and the colour/size layout
                        // has to follow it — see rebuildStatic.
                        tune.perCloud = Math.max(50, Math.min(CLOUD_CAPACITY, Math.round(v)));
                        rebuildStatic(tune.perCloud);
                    } else {
                        tune[key] = v;
                    }
                    return { ...tune };
                },
                /** Put a named figure on screen now; with no argument, lists them all. */
                play: (id) => onFrame.play(id),
                get playing() { return onFrame.playing; },
                get phase() { return onFrame.phase; },
                /** Advance `n` frames by hand — see stepOnce. */
                step: (n = 1) => { for (let i = 0; i < n; i++) stepOnce(); return onFrame.playing; },
                /**
                 * ⚠ The one failure this system cannot report on its own. A NaN reaching a
                 * position — a normalised zero-length vector, a division by a depth of zero —
                 * throws nothing, draws nothing, and takes that cloud out of the field for the rest
                 * of the session. It looks like a pattern that simply forgot one of the five.
                 */
                finite: () => {
                    const used = CLOUD_COUNT * tune.perCloud * 4;
                    for (let i = 0; i < used; i++) if (!Number.isFinite(points[i])) return false;
                    return true;
                },
                /**
                 * Each cloud as the eye would meet it: where it lands on the screen in clip units
                 * (±1 is the edge of the frame) and how brightly it is drawn.
                 *
                 * 🔴 This is what makes "a cloud never jumps" a testable claim rather than an
                 * intention. A jump is a large change of screen position in one frame WHILE the
                 * cloud is visible — two quantities, and neither could be read from outside before.
                 */
                seen: () => bobs.map((b) => {
                    const p = 1 / b.z;
                    return {
                        x: Math.round((b.x * p / aspect) * 1000) / 1000,
                        y: Math.round((-b.y * p) * 1000) / 1000,
                        z: Math.round(b.z * 1000) / 1000,
                        gain: Math.round(b.gain * 1000) / 1000,
                    };
                }),
                /**
                 * How much of each cloud actually lands inside the frame, counted on the POINTS of
                 * the last drawn frame rather than on its centre.
                 *
                 * 🔴 A ring is the case that makes the distinction matter: its centre sits on the
                 * axis, dead in the middle of the screen, for the whole of its trip — so every
                 * centre-based reading says "visible" while the ring itself has long left through
                 * the edges. Judging the tunnel from `seen()` is how a ring that turned back in
                 * plain sight got measured as correct.
                 */
                onscreen: () => bobs.map((b, c) => {
                    const per = tune.perCloud;
                    const base = c * per;
                    let inside = 0;
                    for (let i = 0; i < per; i++) {
                        const j = (base + i) * 4;
                        const p = 1 / points[j + 2];
                        const x = points[j] * p / aspect;
                        const y = -points[j + 1] * p;
                        if (x >= -1 && x <= 1 && y >= -1 && y <= 1) inside++;
                    }
                    return { inside, of: per, z: Math.round(b.z * 1000) / 1000 };
                }),
                stats: () => ({
                    // Where each cloud currently is in depth. Small, and the only way to see from
                    // outside whether a figure that is supposed to come towards you is doing so —
                    // the tunnel's rings looked like they were retreating and nothing else showed
                    // it.
                    depths: bobs.map((b) => Math.round(b.z * 1000) / 1000),
                    points: CLOUD_COUNT * tune.perCloud,
                    drawn: Math.round(CLOUD_COUNT * tune.perCloud * KEEPS[keepIndex]),
                    buffer: `${w}x${h}`, scale: SCALES[scaleIndex], keep: KEEPS[keepIndex],
                    // What the engine actually took from the profile setting — the only way to see
                    // from outside that the control reached it, rather than merely being pressed.
                    speed, calm, drawing: speed > 0,
                }),
            };
        },
    };
}
