/**
 * Promise wrapper around the live-sync worker (see
 * workers/live-sync-worker.js for why the heavy work lives off-thread).
 *
 * Usage from the edit-session page:
 *   const sync = window.UGT.createLiveSync(dataUrl);
 *   sync.fetch() -> Promise<{full}                    (first call)
 *                        | {changed, removed}>        (subsequent calls)
 */

import LiveSyncWorker from '../workers/live-sync-worker.js?worker';

export function createLiveSync(url) {
    const worker = new LiveSyncWorker();
    let nextId = 1;
    const pending = new Map();

    worker.onmessage = (event) => {
        const { id, error, full, changed, removed } = event.data;
        const request = pending.get(id);
        if (!request) return;
        pending.delete(id);
        if (error) {
            request.reject(new Error(error));
        } else {
            request.resolve({ full, changed, removed });
        }
    };

    return {
        fetch() {
            return new Promise((resolve, reject) => {
                const id = nextId++;
                pending.set(id, { resolve, reject });
                worker.postMessage({ id, url });
            });
        }
    };
}
