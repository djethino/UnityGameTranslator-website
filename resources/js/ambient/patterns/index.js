/**
 * The register. Adding a pattern is adding a line here and a file next door — nothing else in the
 * system needs to hear about it, which is the point of the whole arrangement.
 *
 * `kind` decides where it lands in the sequence: two `predefined` drawn at random, then one
 * `intelligent`, then round again. `calm` says whether it may run for someone whose system asks for
 * less motion — a camera charge may not, a drifting ring may.
 */

import trace from './trace.js';
import ronde from './ronde.js';
import escadrille from './escadrille.js';
import orage from './orage.js';
import traversee from './traversee.js';
import miroir from './miroir.js';
import poursuite from './poursuite.js';
import guetApens from './guet-apens.js';

export const PATTERNS = [trace, ronde, escadrille, orage, traversee, miroir, poursuite, guetApens];

export const PREDEFINED = PATTERNS.filter((p) => p.kind === 'predefined');
export const INTELLIGENT = PATTERNS.filter((p) => p.kind === 'intelligent');
