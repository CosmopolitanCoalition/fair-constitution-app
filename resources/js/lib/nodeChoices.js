/* Node surface choices (W-0521). The node keeps each person's choices. This module
   loads them once per page visit, applies the saved value over the defaults, and saves
   changes after a short pause. The browser copy stays as a fallback: private windows,
   blocked storage or a lost connection must leave the page working. */
import { ref, watch } from 'vue';

const PREFIX = 'cga.node.';
const SAVE_DELAY_MS = 600;

let serverChoices = null; // shared promise of { key: value } from the node

function loadServerChoices() {
    if (!serverChoices) {
        serverChoices = (window.axios
            ? window.axios.get('/node/choices').then(r => r.data || {})
            : Promise.resolve({})
        ).catch(() => ({}));
    }
    return serverChoices;
}

function readLocal(key) {
    try {
        const raw = window.localStorage.getItem(PREFIX + key);
        return raw ? JSON.parse(raw) : null;
    } catch { return null; }
}

function writeLocal(key, value) {
    try { window.localStorage.setItem(PREFIX + key, JSON.stringify(value)); } catch { /* ignore */ }
}

export function useNodeChoices(key, initial) {
    const local = readLocal(key);
    const state = ref(local ? { ...initial, ...local } : initial);
    let hydrated = false;
    let applying = false;
    let timer = null;

    const save = () => {
        if (!window.axios) return;
        window.axios.put('/node/choices/' + key, state.value).catch(() => { /* kept in the browser */ });
    };

    watch(state, v => {
        writeLocal(key, v);
        if (!hydrated || applying) return;
        clearTimeout(timer);
        timer = setTimeout(save, SAVE_DELAY_MS);
    }, { deep: true });

    loadServerChoices().then(all => {
        if (all[key] && typeof all[key] === 'object') {
            applying = true;
            state.value = { ...initial, ...all[key] };
            writeLocal(key, state.value);
            // Let the watcher see this change before saves are accepted again.
            Promise.resolve().then(() => { applying = false; });
        } else if (local) {
            save(); // first visit after this release: move the browser copy to the node
        }
        hydrated = true;
    });

    return state;
}
