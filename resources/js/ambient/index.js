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
import { startMotionSettings, offerFigure } from './settings.js';
import { warmGlyphs } from './glyphs.js';

export function startAmbient() {
    // ⚠ Wired before the gate below: the profile screen must be able to turn the background back ON,
    // and a control that only works when the thing it controls is already running is not a control.
    startMotionSettings();

    // Same gate as before: the layout puts `animated-bg` on <body>, and a page that does not want
    // the treatment simply does not carry it.
    if (!document.body.classList.contains('animated-bg')) return;

    /**
     * 🔴 A quiet screen gets nothing that moves — the field included, not just the glitches.
     *
     * The layout already marks the editors and the whole admin area with `data-no-glitch`, and its
     * comment already says "a screen where NOTHING may move or rewrite itself". Until now that was
     * an intention: the glitches obeyed it, the moving field did not, and somebody arbitrating a
     * translation had a corridor flying past behind the text they were weighing.
     *
     * ⚠ One marker, not a second one, and no setting. The reason those screens are still is not a
     * preference — it is the task — so a control that could turn it back on would defeat exactly
     * what the rule is for. And the class stays on the body: it carries the site's background
     * colour and its grain, which are not motion.
     *
     * 🟢 Nothing is started rather than started and suppressed: no WebGL context, no document scan,
     * no language fetch, no timers.
     */
    if (document.body.hasAttribute('data-no-glitch')) return;

    // Where letters come from, for whoever needs them. Declared out here rather than inside the
    // engine's branch: the glyph warm-up below needs it too, and a machine with no WebGL still
    // benefits from having the alphabets ready.
    const strings = () => {
        // Asked for, not waited on. The first letter is drawn from the words on the page; by the
        // second the banks have landed and the alphabets open up. Requesting it here rather than in
        // the glitch means the background keeps its variety even with glitches off.
        warmBanks();
        const bank = bankStrings();
        return bank.length ? bank : visibleStrings();
    };

    // Returns null when WebGL is unavailable, having marked <body> so CSS can take over.
    const engine = createEngine();
    if (engine) {
        const conductor = createConductor({ engine, pickAnchor, strings });
        engine.start(conductor);
        // ⚠ Handed over rather than imported the other way: the settings card is wired before this
        // exists, so it asks for a figure through a slot it can find empty. On a machine with no
        // WebGL the slot stays empty and the card's Test button refuses instead of pretending.
        offerFigure((id) => conductor.play(id));
    }

    // Decoration on top of a page that has to be usable first: deferred to idle so neither the DOM
    // scan nor the language fetch competes with the first render.
    const later = window.requestIdleCallback || ((fn) => setTimeout(fn, 1200));
    later(() => {
        startPing();
        startLingua();

        // 🔴 Rasterise letters before a figure asks for them. Entering the word or letter pattern
        // measured 9 to 32 ms — one to two dropped frames — because every character was painted to
        // a canvas and read back at that moment, up to a hundred of them. They are cached now, but
        // a cache only helps from the second time; this is what makes the first one cheap too.
        //
        // ⚠ A strict budget per slice, and it stops when the page has something better to do. The
        // work that prevents jank must not be the jank.
        const chew = (deadline) => {
            const done = warmGlyphs(strings(), deadline && deadline.timeRemaining
                ? Math.min(8, deadline.timeRemaining()) : 6);
            if (!done) later(chew);
        };
        later(chew);
        // 🔴 One clock for all three. `fond` is absent on a machine with no WebGL, and the
        // orchestra simply skips that part — the page still flinches and still slips languages.
        startOrchestra({
            fond: engine ? () => engine.hiss() : null,
            visuel: firePing,
            langue: fireLingua,
        });
    });
}
