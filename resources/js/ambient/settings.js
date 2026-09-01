/**
 * The controls on the profile screen, wired to `motion.js`.
 *
 * ── Why plain JS and not an Alpine component ───────────────────────────────────────────────────
 * ⚠ This site runs @alpinejs/csp, whose parser accepts a property access or a bare call and
 * nothing else — `pick('background', 'off')` is not rejected, it is evaluated to NOTHING. The site
 * already hit this with the language picker and answered it the same way: data attributes read by
 * one delegated listener. Doing it here in Alpine would look right and do nothing.
 *
 * ── Why there is no Save button ────────────────────────────────────────────────────────────────
 * The thing being configured is running behind the screen while it is configured. Applying at once
 * means the control IS its own preview: you press Calm and the field slows down under the card. A
 * Save button would put a round trip between a choice and its only meaningful feedback.
 *
 * That is also why the card says out loud that it applies to this browser — a screen with no Save
 * button, on a page whose other cards have one, has to account for itself.
 */

import { level, isChosen, setLevel, systemAsksReduced, onMotionChange } from './motion.js';
import { fireNow as fireGlitch } from '../glitch/ping.js';
import { fireNow as fireLingua } from '../glitch/lingua.js';

/**
 * How this screen asks for a figure to be shown now.
 *
 * ⚠ Registered rather than imported, because the thing that can do it does not exist yet when this
 * file runs: the card is wired before the engine is built, deliberately, so that turning the
 * background back ON from here works at all. Null until an engine exists — and on a machine with no
 * WebGL it stays null, which is exactly when the button must refuse.
 */
let showFigure = null;
export function offerFigure(fn) { showFigure = fn; }

const ON = ['bg-purple-600', 'text-white', 'shadow'];
const OFF = ['text-gray-400', 'hover:text-white', 'hover:bg-gray-700/60'];

function paint(root) {
    root.querySelectorAll('[data-motion-control]').forEach((group) => {
        const kind = group.dataset.motionControl;
        const current = level(kind);
        group.querySelectorAll('[data-motion-level]').forEach((button) => {
            const active = button.dataset.motionLevel === current;
            button.classList.remove(...(active ? OFF : ON));
            button.classList.add(...(active ? ON : OFF));
            // The pressed state is what a screen reader announces; the colour is only for the eye.
            button.setAttribute('aria-pressed', String(active));
        });
    });

    // Said only when it is true and still in force. Somebody seeing "Calm" selected without having
    // chosen it deserves to know who chose it — otherwise the screen looks like it has a mind of
    // its own.
    const following = systemAsksReduced() && !(isChosen('background') && isChosen('glitch'));
    root.querySelectorAll('[data-motion-system]').forEach((el) => { el.hidden = !following; });

    // ⚠ The corridor counts here too: the reset below clears it, so leaving it out would hide the
    // only way back for somebody whose sole choice was to turn it on.
    const chosen = isChosen('background') || isChosen('glitch') || isChosen('tunnel');
    root.querySelectorAll('[data-motion-reset]').forEach((el) => { el.hidden = !chosen; });

    // No dead ends: previewing a glitch that has been turned off would do nothing, so the control
    // says so before it is pressed rather than after.
    const dim = (el, off) => {
        el.disabled = off;
        el.classList.toggle('opacity-40', off);
        el.classList.toggle('cursor-not-allowed', off);
    };
    root.querySelectorAll('[data-motion-preview]').forEach((el) => dim(el, level('glitch') === 'off'));

    // ⚠ The corridor's controls follow the blobs, because it IS one of them: with the field switched
    // off there is nothing for a corridor to happen in, and a toggle that could be set in that state
    // would be a preference nobody can see the effect of. The same rule as above, one row down.
    const noField = level('background') === 'off';
    root.querySelectorAll('[data-motion-sub="tunnel"]').forEach((el) => {
        el.classList.toggle('opacity-40', noField);
        el.querySelectorAll('button').forEach((b) => dim(b, noField));
    });
    // And the button additionally needs something able to show it.
    root.querySelectorAll('[data-motion-tunnel]').forEach((el) => dim(el, noField || !showFigure));
}

export function startMotionSettings() {
    const root = document.querySelector('[data-motion-settings]');
    if (!root) return;

    paint(root);
    // Repainted on every change, wherever it came from — including the operating system being
    // toggled in another window while this screen is open.
    onMotionChange(() => paint(root));

    root.addEventListener('click', (event) => {
        const pick = event.target.closest('[data-motion-level]');
        if (pick) {
            setLevel(pick.closest('[data-motion-control]').dataset.motionControl, pick.dataset.motionLevel);
            return;
        }

        if (event.target.closest('[data-motion-reset]')) {
            setLevel('background', null);
            setLevel('glitch', null);
            setLevel('tunnel', null);
            return;
        }

        if (event.target.closest('[data-motion-tunnel]')) {
            // 🔴 Through the ordinary handover, not a special path. `play` goes by the same
            // `advance()` the rotation uses, so the rings are reset to blobs, a transition is dealt
            // from the same deck, and the field arrives the way it always would — which is the whole
            // point of a preview. A second route would be showing something the rotation never does.
            if (showFigure) showFigure('tunnel');
            return;
        }

        if (event.target.closest('[data-motion-preview]')) {
            // Both kinds at once, because that is what "glitches" means on this screen: the
            // distortion and the word that changes language are one setting, so one preview.
            fireGlitch();
            fireLingua();
        }
    });
}
