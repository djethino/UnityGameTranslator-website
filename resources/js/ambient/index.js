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
import { startPing, fireNow as firePing } from '../glitch/ping.js';
import { startLingua, fireNow as fireLingua, bankStrings, warmBanks } from '../glitch/lingua.js';
import { startOrchestra } from '../glitch/orchestra.js';
import { startMotionSettings } from './settings.js';

export function startAmbient() {
    // ⚠ Wired before the gate below: the profile screen must be able to turn the background back ON,
    // and a control that only works when the thing it controls is already running is not a control.
    startMotionSettings();

    // Same gate as before: the layout puts `animated-bg` on <body>, and a page that does not want
    // the treatment simply does not carry it.
    if (!document.body.classList.contains('animated-bg')) return;

    // Returns null when WebGL is unavailable, having marked <body> so CSS can take over.
    const engine = createEngine();
    if (engine) {
        const strings = () => {
            // Asked for, not waited on. The first letter is drawn from the words on the page; by
            // the second the banks have landed and the alphabets open up. Requesting it here rather
            // than in the glitch means the background keeps its variety even with glitches off.
            warmBanks();
            const bank = bankStrings();
            return bank.length ? bank : visibleStrings();
        };
        engine.start(createConductor({ engine, pickAnchor, strings }));
    }

    // Decoration on top of a page that has to be usable first: deferred to idle so neither the DOM
    // scan nor the language fetch competes with the first render.
    const later = window.requestIdleCallback || ((fn) => setTimeout(fn, 1200));
    later(() => {
        startPing();
        startLingua();
        // 🔴 One clock for all three. `fond` is absent on a machine with no WebGL, and the
        // orchestra simply skips that part — the page still flinches and still slips languages.
        startOrchestra({
            fond: engine ? () => engine.hiss() : null,
            visuel: firePing,
            langue: fireLingua,
        });
    });
}
