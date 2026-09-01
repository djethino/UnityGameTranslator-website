/**
 * The register. Adding a pattern is adding a line here and a file next door — nothing else in the
 * system needs to hear about it, which is the point of the whole arrangement.
 *
 * 🟢 That claim was tested rather than asserted: eleven were added in one go, and the only things
 * outside `patterns/` that had to change were three properties a bob can carry (`yaw`, `twist`) and
 * three readings a pattern can ask for (the scroll's sign, something visible on the page, and how
 * big a cloud actually is). Every figure itself is one file.
 *
 * `kind` decides where it lands in the sequence: two `predefined` drawn at random, then one
 * `intelligent`, then round again.
 *
 * `calm` says whether it may run for someone who asked for less motion — a camera charge may not,
 * a drifting globe may.
 *
 * `needsPointer` keeps a pattern out of the rotation on a device where no pointer has ever been
 * seen: on an untouched phone it would run its own fallback for its whole length, which is a figure
 * whose entire content is "nothing is happening".
 *
 * `square` stops the conductor spreading the pattern's x to fill a widescreen field — for the two
 * that draw letters, which a 16:9 stretch would ruin.
 */

import trace from './trace.js';
import mot from './mot.js';
import ronde from './ronde.js';
import globe from './globe.js';
import escadrille from './escadrille.js';
import orage from './orage.js';
import traversee from './traversee.js';
import tunnel from './tunnel.js';
import miroir from './miroir.js';
import copper from './copper.js';
import reflet from './reflet.js';

import poursuite from './poursuite.js';
import evitement from './evitement.js';
import sillage from './sillage.js';
import capture from './capture.js';
import soulignement from './soulignement.js';
import encadrement from './encadrement.js';
import ballant from './ballant.js';
import guetApens from './guet-apens.js';

export const PATTERNS = [
    // Predefined — what the field does when nothing outside it is happening.
    trace, mot, ronde, globe, escadrille, orage, traversee, tunnel, miroir, copper, reflet,
    // Intelligent — what it does about the reader, the page, or the scrolling.
    poursuite, evitement, sillage, capture, soulignement, encadrement, ballant, guetApens,
];

export const PREDEFINED = PATTERNS.filter((p) => p.kind === 'predefined');
export const INTELLIGENT = PATTERNS.filter((p) => p.kind === 'intelligent');
