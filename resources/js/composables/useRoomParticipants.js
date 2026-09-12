import { computed, onScopeDispose, ref, watch } from 'vue';
import axios from 'axios';

/** Refresh only connected identities; roster transport never owns the AV connection. */
export function useRoomParticipants({ url, room, participants, preview }) {
    const resolved = ref({});
    let generation = 0, timer = null, controller = null, disposed = false, failures = 0;
    let activeScope = '';
    const handles = () => [...new Set(participants().map(person => person.identity)
        .filter(handle => typeof handle === 'string' && handle.startsWith('@')))].sort();
    const cancel = () => {
        generation++;
        clearTimeout(timer);
        controller?.abort();
        controller = null;
    };
    async function refresh() {
        cancel();
        const scope = JSON.stringify([url(), room()]);
        if (scope !== activeScope) { resolved.value = {}; failures = 0; activeScope = scope; }
        const currentHandles = handles();
        resolved.value = Object.fromEntries(Object.entries(resolved.value).filter(([handle]) => currentHandles.includes(handle)));
        if (disposed || !url() || !room() || currentHandles.length === 0) return;
        const attempt = generation, expectedRoom = room(), endpoint = url();
        const current = () => !disposed && attempt === generation;
        controller = new AbortController();
        const signal = controller.signal;
        try {
            for (let start = 0; start < currentHandles.length; start += 100) {
                const batch = currentHandles.slice(start, start + 100);
                const { data } = await axios.post(endpoint, { handles: batch }, { signal, timeout: 10000 });
                if (!current()) return;
                if (data?.roomId !== expectedRoom || !Array.isArray(data.roster)) throw new Error('Wrong room roster');
                const rows = new Map(data.roster.filter(row => batch.includes(row.handle)).map(row => [row.handle, row]));
                const update = {};
                // Missing records clear old offices too; participant metadata never supplies a role.
                for (const handle of batch) update[handle] = rows.get(handle) ?? { handle, role: 'guest', seat: null };
                resolved.value = { ...resolved.value, ...update };
            }
            failures = 0;
        } catch (error) {
            if (!current()) return;
            failures++;
            if ([401, 403].includes(error?.response?.status)) {
                resolved.value = Object.fromEntries(currentHandles.map(handle => [handle, { handle, role: 'guest', seat: null }]));
            }
        } finally {
            if (current()) {
                controller = null;
                timer = setTimeout(refresh, failures ? Math.min(60000, 5000 * 2 ** Math.min(failures - 1, 4)) : 30000);
            }
        }
    }
    watch(() => JSON.stringify([url(), room(), handles()]), refresh, { immediate: true });
    onScopeDispose(() => { disposed = true; cancel(); });
    const roster = computed(() => {
        const people = new Map(preview().map(person => [person.handle ?? person.identity, person]));
        for (const [handle, person] of Object.entries(resolved.value)) people.set(handle, person);
        return [...people.values()];
    });
    return { roster };
}
