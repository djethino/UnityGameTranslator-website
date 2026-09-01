/**
 * Wiring. The only file that knows all the pieces exist.
 *
 * Two things are deliberate here.
 *
 * **`strings` is a function, not an array.** The language bank arrives at idle, and the letter
 * pattern reads it for glyph candidates. Asked at the moment a letter is needed, it returns
 * whatever has landed by then and falls back to the words on the page before anything has. An
 * array would have frozen the answer at boot, when it is always empty.
 *
 * **The two glitches do not depend on the background.** A machine with no WebGL still gets the
 * ambient glitch and the language glitch; only the moving field is missing, and CSS puts a still
 * one in its place.
 */

import { createEngine } from './engine.js';
import { createConductor } from './conductor.js';
import { pickAnchor, visibleStrings } from '../glitch/targets.js';
import { startPing } from '../glitch/ping.js';
import { startLingua, bankStrings } from '../glitch/lingua.js';

export function startAmbient() {
    // Same gate as before: the layout puts `animated-bg` on <body>, and a page that does not want
    // the treatment simply does not carry it.
    if (!document.body.classList.contains('animated-bg')) return;

    // Returns null when WebGL is unavailable, having marked <body> so CSS can take over.
    const engine = createEngine();
    if (engine) {
        const strings = () => {
            const bank = bankStrings();
            return bank.length ? bank : visibleStrings();
        };
        engine.start(createConductor({ engine, pickAnchor, strings }));
    }

    // Decoration on top of a page that has to be usable first: deferred to idle so neither the DOM
    // scan nor the language fetch competes with the first render.
    const later = window.requestIdleCallback || ((fn) => setTimeout(fn, 1200));
    later(() => { startPing(); startLingua(); });
}
