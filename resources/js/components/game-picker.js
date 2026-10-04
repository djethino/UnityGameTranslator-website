// The game list of the site's pages — search as you type, pick one hit. Used where a game is CHOSEN:
// the upload form (a new translation) and a translation's settings (changing its game).
//
// 🔴 **One list, the same answer the mod and the Manager show** (`/api/games/search-external`,
// GameSearchService::searchFull): the catalogue first, then Steam, IGDB, RAWG — each game once,
// told apart by its ids, and a Steam id or Steam page address typed in is searched as that id. What
// a pick hands back is the hit's SOURCE and its ID THERE (a Steam hit by its Steam id), sent to the
// server as `game_pick` — the only thing the server trusts about it (App\Services\GameFiling).
//
// ⚠ Rows are built with the DOM, never innerHTML: a name comes from IGDB, RAWG, Steam or our own
// catalogue, none of which this page controls.

const SOURCE_TAGS = {
    local: ['Local', 'bg-green-600'],
    steam: ['Steam', 'bg-gray-600'],
    igdb: ['IGDB', 'bg-purple-600'],
    rawg: ['RAWG', 'bg-blue-600'],
};

/** The pair the server takes back for a hit: its source, and its id in that source. */
export function pickOf(hit) {
    const id = hit.source === 'steam' ? hit.steam_id : hit.id;

    return { source: hit.source || '', id: id === undefined || id === null ? '' : String(id) };
}

/**
 * @param {object} o
 * @param {HTMLInputElement} o.input       the search box
 * @param {HTMLElement}      o.list        where the hits are drawn
 * @param {HTMLElement}     [o.loading]    shown while asking
 * @param {string}           o.emptyText   said when nothing is found — what to do next
 * @param {(hit) => void}    o.onPick      a hit was picked
 * @param {() => void}      [o.onType]     the box changed: any earlier pick no longer stands
 */
export function attachGamePicker({ input, list, loading, emptyText, onPick, onType }) {
    let timer = null;
    let asked = 0;

    const hide = () => list.classList.add('hidden');

    function row(hit) {
        const line = document.createElement('div');
        line.className = 'flex items-center gap-3 px-4 py-2 hover:bg-gray-600 cursor-pointer';

        let picture;
        if (hit.image_url) {
            picture = document.createElement('img');
            picture.src = hit.image_url;
            picture.className = 'w-10 h-14 object-cover rounded flex-shrink-0';
            picture.addEventListener('error', () => { picture.style.display = 'none'; });
        } else {
            picture = document.createElement('div');
            picture.className = 'w-10 h-14 bg-gray-600 rounded flex-shrink-0 flex items-center justify-center';
            const icon = document.createElement('i');
            icon.className = 'fas fa-gamepad text-gray-400';
            picture.append(icon);
        }

        const name = document.createElement('div');
        name.className = 'font-medium truncate';
        name.textContent = hit.name;

        const tag = SOURCE_TAGS[hit.source];
        if (tag) {
            const chip = document.createElement('span');
            chip.className = 'text-xs ' + tag[1] + ' px-1.5 py-0.5 rounded ml-2';
            chip.textContent = tag[0];
            name.append(chip);
        }

        const wrap = document.createElement('div');
        wrap.className = 'flex-1 min-w-0';
        wrap.append(name);
        line.append(picture, wrap);

        line.addEventListener('click', () => {
            hide();
            onPick(hit);
        });

        return line;
    }

    async function search(q) {
        const mine = ++asked;
        loading?.classList.remove('hidden');

        try {
            const res = await fetch('/api/games/search-external?q=' + encodeURIComponent(q));
            const hits = await res.json();

            // A slower answer to an older query must not overwrite the newer one.
            if (mine !== asked) return;

            list.textContent = '';
            if (hits.length === 0) {
                const none = document.createElement('div');
                none.className = 'px-4 py-3 text-gray-400 text-sm';
                none.textContent = emptyText;
                list.append(none);
            } else {
                hits.forEach(hit => list.append(row(hit)));
            }
            list.classList.remove('hidden');
        } catch (e) {
            console.error('Game search error:', e);
        } finally {
            if (mine === asked) loading?.classList.add('hidden');
        }
    }

    input.addEventListener('input', () => {
        onType?.();

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

    document.addEventListener('click', (e) => {
        if (!input.contains(e.target) && !list.contains(e.target)) hide();
    });

    return { search };
}
