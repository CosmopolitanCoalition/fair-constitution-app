/* Node surface choices. Held in this browser until the server wiring items land
   (rubric work items W-0521 onward). Every read and write is guarded: private
   windows and blocked storage must leave the page working. */
import { ref, watch } from 'vue';

const PREFIX = 'cga.node.';

export function useNodeChoices(key, initial) {
    let start = initial;
    try {
        const raw = window.localStorage.getItem(PREFIX + key);
        if (raw) start = { ...initial, ...JSON.parse(raw) };
    } catch { /* storage unavailable: keep defaults */ }
    const state = ref(start);
    watch(state, v => {
        try { window.localStorage.setItem(PREFIX + key, JSON.stringify(v)); } catch { /* ignore */ }
    }, { deep: true });
    return state;
}
