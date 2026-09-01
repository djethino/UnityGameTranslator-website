/**
 * A sine table — the oldest trick in the demo book, and it still pays.
 *
 * 🔴 Why it exists here, in numbers rather than in nostalgia. The field integrates eleven thousand
 * points a frame, and each one asked for three transcendental functions to compute its private
 * shimmer: thirty-three thousand calls to `Math.sin` per frame. Measured in this browser,
 * `Math.sin` costs **23.6 ns** and a table lookup **1.2 ns** — a factor of twenty — which came to
 * **0.74 ms of a 1.4 ms frame**. Half the cost of the whole background was spent computing sines to
 * sixteen digits in order to nudge a dot by four thousandths of a screen.
 *
 * ── What the precision actually has to be ──────────────────────────────────────────────────────
 * The shimmer's amplitude is 0.004 to 0.016 field units. With 4096 entries the worst angular error
 * is π/4096, so the worst positional error is about **1.2 × 10⁻⁵ field units** — roughly a
 * thousandth of a pixel. There is nothing to interpolate: the table is already finer than the
 * screen by three orders of magnitude.
 *
 * ⚠ Which is exactly why this is not a general-purpose replacement for `Math.sin`, and must not
 * become one. It is right for a small oscillation added to a position. It is wrong for a rotation
 * matrix, where the same error turns into a visible wobble of the whole body — those still call the
 * real thing, twice per cloud, which costs nothing worth counting.
 *
 * ⚠ Negative angles work, and not by accident: `&` converts through a 32-bit integer first, so
 * `-326 & 4095` is 3770, which is the same entry as `2π − θ`. The table wraps for free in both
 * directions and needs no branch.
 */

const BITS = 12;
const SIZE = 1 << BITS;            // 4096
const MASK = SIZE - 1;
const SCALE = SIZE / (Math.PI * 2);

const TABLE = new Float32Array(SIZE);
for (let i = 0; i < SIZE; i++) TABLE[i] = Math.sin(i / SCALE);

/** A quarter turn, in table entries — how cosine is read out of the same table. */
const QUARTER = SIZE >> 2;

export function sin(a) {
    return TABLE[(a * SCALE) & MASK];
}

export function cos(a) {
    return TABLE[((a * SCALE) + QUARTER) & MASK];
}

/** The table itself, for a caller that wants to index it directly in a tight loop. */
export const SINE = { table: TABLE, mask: MASK, scale: SCALE, quarter: QUARTER };
