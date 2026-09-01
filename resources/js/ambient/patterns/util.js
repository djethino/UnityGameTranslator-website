/** Small shared maths for the patterns. Nothing here knows anything about bobs. */

export const clamp = (v, a = 0, b = 1) => (v < a ? a : v > b ? b : v);
export const lerp = (a, b, t) => a + (b - a) * t;

/** 0 below `a`, 1 above `b`, and an S-curve in between — the workhorse for phase transitions. */
export function smoothstep(a, b, v) {
    const t = clamp((v - a) / (b - a));
    return t * t * (3 - 2 * t);
}

/** Ease that leaves and arrives at rest. Anything that should look decided rather than mechanical. */
export const easeInOut = (t) => (t < 0.5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2);

/** Ease that starts fast and settles — arrivals, impacts. */
export const easeOut = (t) => 1 - Math.pow(1 - t, 3);

/**
 * Seeded generator. Each run of a pattern gets its own, so a `ronde` is never twice the same ronde
 * yet stays coherent with itself from start to finish — which `Math.random()` per frame cannot do.
 */
export function rngFrom(seed) {
    let a = seed >>> 0;
    return () => {
        a = (a + 0x6D2B79F5) >>> 0;
        let t = Math.imul(a ^ (a >>> 15), 1 | a);
        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

/**
 * Undo the projection: where must a cloud BE, in the field, to appear at a given point of the
 * screen at a given depth?
 *
 * 🔴 Both factors are needed and forgetting either is silent. The vertex shader draws a point at
 * `x / z / aspect`, so a pattern aiming at a screen position has to multiply by BOTH — miss the
 * depth and everything lands near the middle, miss the aspect and it lands too far left or right on
 * a wide display. Neither produces an error, only a figure pointing at nothing.
 *
 * `fx` and `fy` are the screen position as -1…1 from the centre, y downwards.
 */
export function fromScreen(fx, fy, z, aspect) {
    return { x: fx * z * aspect, y: fy * z, z };
}

/** A DOM rectangle as that same -1…1 pair, plus its size in the same units. */
export function screenRect(rect) {
    const w = window.innerWidth;
    const h = window.innerHeight;
    return {
        x: ((rect.left + rect.width / 2) / w) * 2 - 1,
        y: ((rect.top + rect.height / 2) / h) * 2 - 1,
        top: (rect.top / h) * 2 - 1,
        bottom: (rect.bottom / h) * 2 - 1,
        left: (rect.left / w) * 2 - 1,
        right: (rect.right / w) * 2 - 1,
        halfWidth: rect.width / w,
        halfHeight: rect.height / h,
        onScreen: rect.width > 0 && rect.bottom > 0 && rect.top < h,
    };
}

/** A value in [-1, 1] that never repeats, from three sines at irrational ratios. */
export function wander(t, phase = 0) {
    return (Math.sin(t * 0.618 + phase) * 0.5
          + Math.sin(t * 0.414 + phase * 1.7) * 0.33
          + Math.sin(t * 0.732 + phase * 2.3) * 0.17);
}
