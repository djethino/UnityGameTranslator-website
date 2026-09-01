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

    const chosen = isChosen('background') || isChosen('glitch');
    root.querySelectorAll('[data-motion-reset]').forEach((el) => { el.hidden = !chosen; });

    // No dead ends: previewing a glitch that has been turned off would do nothing, so the control
    // says so before it is pressed rather than after.
    root.querySelectorAll('[data-motion-preview]').forEach((el) => {
        const off = level('glitch') === 'off';
        el.disabled = off;
        el.classList.toggle('opacity-40', off);
        el.classList.toggle('cursor-not-allowed', off);
    });
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
