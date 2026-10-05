// The game list of the site's pages — search as you type, pick one hit. Used where a game is CHOSEN:
// the upload form (a new translation) and a translation's settings (changing its game).
//
// 🔴 **One list, the same answer the mod and the Manager show** (`/api/games/search-external`,
// GameSearchService::searchFull): the catalogue first, then Steam, IGDB, RAWG — each game once,
// told apart by its ids, and a Steam id or Steam page address typed in is searched as that id. What
// a pick hands back is the hit's SOURCE and its ID THERE (a Steam hit by its Steam id), sent to the
// server as `game_pick` — the only thing the server trusts about it (App\Services\GameFiling).
//
// ⚠ Each row says what tells two games of one title apart — the ids it answers to in every store,
// the year, who made and published it — and links each store's page, so a person can check a game
// before picking it (user, 2026-10-04). A link opens the page; it never picks.
//
// ⚠ Rows are built with the DOM, never innerHTML: a name comes from IGDB, RAWG, Steam or our own
// catalogue, none of which this page controls.

import { gameCover } from '../game-cover.js';

const SOURCE_TAGS = {
    local: ['Local', 'bg-green-600'],
    steam: ['Steam', 'bg-gray-600'],
    igdb: ['IGDB', 'bg-purple-600'],
    rawg: ['RAWG', 'bg-blue-600'],
};

// The stores whose numbers a person can look up; a card's own number on this site is not one.
const STORE_IDS = ['steam', 'igdb', 'rawg'];

/** The pair the server takes back for a hit: its source, and its id in that source. */
export function pickOf(hit) {
    const id = hit.source === 'steam' ? hit.steam_id : hit.id;

    return { source: hit.source || '', id: id === undefined || id === null ? '' : String(id) };
}

/** Whether a hit is the game a pick names — by any id the hit answers to, not only its own. */
function names(pick, hit) {
    if (!pick || pick.source === '' || pick.id === '') return false;

    const ids = hit.ids || {};
    if (ids[pick.source] !== undefined && String(ids[pick.source]) === pick.id) return true;

    const own = pickOf(hit);
    return own.source === pick.source && own.id === pick.id;
}

function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
}

function picture(hit, size) {
    if (hit.image_url) return gameCover(hit.image_url, '', size + ' rounded');

    const box = el('div', size + ' bg-gray-600 rounded flex-shrink-0 flex items-center justify-center');
    box.append(el('i', 'fas fa-gamepad text-gray-400'));
    return box;
}

/**
 * What tells this game apart, on one line: its id in each store, its year, who made and published
 * it — each only when known.
 */
function facts(hit) {
    const parts = [];
    const ids = hit.ids || {};

    STORE_IDS.forEach(store => {
        const id = ids[store] ?? (store === 'steam' ? hit.steam_id : (hit.source === store ? hit.id : null));
        if (id !== undefined && id !== null && id !== '') parts.push(SOURCE_TAGS[store][0] + ' ' + id);
    });

    if (hit.year) parts.push(String(hit.year));

    const developers = (hit.developers || []).join(', ');
    const publishers = (hit.publishers || []).filter(p => !(hit.developers || []).includes(p)).join(', ');
    if (developers || publishers) parts.push([developers, publishers].filter(Boolean).join(' / '));

    return parts.join(' · ');
}

/** One link per store page the hit has — opens the page in a new tab, never picks the hit. */
function links(hit) {
    const wrap = el('div', 'flex items-center gap-1 flex-shrink-0');

    Object.entries(hit.pages || {}).forEach(([source, url]) => {
        if (typeof url !== 'string' || !/^https:\/\//.test(url) && !url.startsWith(window.location.origin + '/')) return;

        const tag = SOURCE_TAGS[source];
        const a = el('a', 'text-xs text-gray-300 hover:text-white border border-gray-500 hover:border-gray-300 rounded px-1.5 py-0.5 whitespace-nowrap');
        a.href = url;
        a.target = '_blank';
        a.rel = 'noopener noreferrer';
        a.textContent = tag ? tag[0] : source;
        a.append(el('i', 'fas fa-external-link-alt ml-1 text-[0.6rem]'));
        a.addEventListener('click', e => e.stopPropagation());
        wrap.append(a);
    });

    return wrap;
}

/** The name, its source chip, the facts line and the store links — a row and the chosen card alike. */
function body(hit, extraChip) {
    // A card's names in the other stores follow its title in brackets — why it answered a search
    // for one of them; the mod and the Manager write it the same way (common GameCandidates.Row).
    const others = (hit.other_names || []).filter(n => typeof n === 'string' && n !== '');
    const name = el('div', 'font-medium truncate', others.length ? hit.name + ' (' + others.join(' / ') + ')' : hit.name);

    const tag = SOURCE_TAGS[hit.source];
    if (tag) name.append(el('span', 'text-xs ' + tag[1] + ' px-1.5 py-0.5 rounded ml-2', tag[0]));
    if (extraChip) name.append(extraChip);

    const text = el('div', 'flex-1 min-w-0');
    text.append(name);

    const line = facts(hit);
    if (line) text.append(el('div', 'text-xs text-gray-400 truncate', line));

    return [text, links(hit)];
}

/**
 * @param {object} o
 * @param {HTMLInputElement} o.input       the search box
 * @param {HTMLElement}      o.list        where the hits are drawn
 * @param {HTMLElement}     [o.loading]    shown while asking
 * @param {HTMLElement}     [o.chosenBox]  where the hit picked is shown, with what tells it apart
 * @param {string}           o.emptyText   said when nothing is found — what to do next
 * @param {object}          [o.current]    the pick of the game already held: marked, and not offered
 * @param {string}          [o.currentText] the chip that marks it
 * @param {(hit) => void}    o.onPick      a hit was picked
 * @param {() => void}      [o.onType]     the box changed: any earlier pick no longer stands
 */
export function attachGamePicker({ input, list, loading, chosenBox, emptyText, current, currentText, onPick, onType }) {
    let timer = null;
    let asked = 0;
    // The query the list now shows the answer to, and the hit picked from it.
    let shownFor = null;
    let shownHits = [];
    let chosen = null;

    const hide = () => list.classList.add('hidden');

    function row(hit) {
        const isCurrent = names(current, hit);
        const isChosen = chosen !== null && names(pickOf(chosen), hit);

        const line = el('div', 'flex items-center gap-3 px-4 py-2 '
            + (isCurrent ? 'opacity-60 cursor-default' : 'hover:bg-gray-600 cursor-pointer')
            + (isChosen ? ' bg-gray-600' : ''));

        const chip = isCurrent && currentText
            ? el('span', 'text-xs bg-gray-500 px-1.5 py-0.5 rounded ml-2', currentText)
            : null;

        line.append(picture(hit, 'w-10 h-14'), ...body(hit, chip));

        if (isChosen) line.append(el('i', 'fas fa-check text-green-400 flex-shrink-0'));

        // The game already held is shown so a person sees it is there, never offered: picking it
        // would change nothing.
        if (!isCurrent) {
            line.addEventListener('click', () => {
                hide();
                choose(hit);
                onPick(hit);
            });
        }

        return line;
    }

    function draw() {
        list.textContent = '';
        if (shownHits.length === 0) {
            list.append(el('div', 'px-4 py-3 text-gray-400 text-sm', emptyText));
        } else {
            shownHits.forEach(hit => list.append(row(hit)));
        }
    }

    /** The hit that is now the form's game — shown in the chosen card, marked in the list. */
    function choose(hit) {
        chosen = hit;

        if (!chosenBox) return;
        chosenBox.textContent = '';
        if (hit === null) {
            chosenBox.classList.add('hidden');
            return;
        }

        const card = el('div', 'flex items-center gap-3 bg-gray-700/60 border border-purple-500/60 rounded-lg px-3 py-2');
        card.append(picture(hit, 'w-10 h-14'), ...body(hit, null));
        chosenBox.append(card);
        chosenBox.classList.remove('hidden');
    }

    async function search(q) {
        const mine = ++asked;
        loading?.classList.remove('hidden');

        try {
            const res = await fetch('/api/games/search-external?q=' + encodeURIComponent(q));
            const hits = await res.json();

            // A slower answer to an older query must not overwrite the newer one.
            if (mine !== asked) return;

            shownFor = q;
            shownHits = hits;
            draw();
            list.classList.remove('hidden');
        } catch (e) {
            console.error('Game search error:', e);
        } finally {
            if (mine === asked) loading?.classList.add('hidden');
        }
    }

    input.addEventListener('input', () => {
        onType?.();
        choose(null);

        const q = input.value.trim();
        clearTimeout(timer);

        if (q.length < 2) {
            asked++;
            hide();
            loading?.classList.add('hidden');
            return;
        }

        // Typing is the event; the pause only groups the keystrokes of one word into one question.
        timer = setTimeout(() => search(q), 300);
    });

    // Back in the box: the list it last showed comes back as it was, without asking again — the
    // person is still choosing among the same answers (user, 2026-10-04). After a pick the box holds
    // the picked name, not the query, so the list of the query that found it is the one reopened.
    const reopen = () => {
        if (shownFor !== null && list.classList.contains('hidden')) {
            draw();
            list.classList.remove('hidden');
        }
    };
    input.addEventListener('focus', reopen);
    input.addEventListener('click', reopen);

    document.addEventListener('click', (e) => {
        if (!input.contains(e.target) && !list.contains(e.target)) hide();
    });

    return { search, choose };
}
