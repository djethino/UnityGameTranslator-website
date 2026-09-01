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

/** A value in [-1, 1] that never repeats, from three sines at irrational ratios. */
export function wander(t, phase = 0) {
    return (Math.sin(t * 0.618 + phase) * 0.5
          + Math.sin(t * 0.414 + phase * 1.7) * 0.33
          + Math.sin(t * 0.732 + phase * 2.3) * 0.17);
}
