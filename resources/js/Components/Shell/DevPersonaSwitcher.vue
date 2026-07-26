<script setup>
/**
 * Shell/DevPersonaSwitcher — become someone else, with a mouse.
 *
 * WHY THIS EXISTS. The endpoints to switch persona have been there for a
 * while, and none of them was reachable by a human: `/dev/users` returns
 * JSON rather than a page, `/dev/impersonate/{user}` and `/dev/login-as` are
 * POST-only, and DevBar.vue was purely presentational — its own docblock
 * promised "the controls (impersonation select, …) mount via the default
 * slot", and nothing ever mounted into that slot. So the capability existed
 * and could only be driven by hand-crafted requests.
 *
 * That matters more than it sounds: every card on the playtest worksheet
 * begins by naming the role you must be in, and the journeys move between
 * citizen, candidate, legislator and judge. A walkthrough whose every step
 * starts "be this person" is unusable if you cannot become anyone.
 *
 * TWO PATHS, because they are gated differently:
 *   - `/dev/impersonate/{id}` is OPERATOR-ONLY and remembers who you really
 *     are, so you can come back. Preferred.
 *   - `/dev/login-as` has no operator gate and simply logs in as the target.
 *     Used as the fallback when impersonation is refused, so a non-operator
 *     can still drive a walkthrough.
 *
 * Every switch is appended to the audit chain by the server as a dev action,
 * so a played timeline never reads as a lived one. Dev-only: the whole bar is
 * server-gated on sandbox game mode.
 */
import { ref, watch, computed } from 'vue';

const props = defineProps({
    /** { name } of the impersonated user, or null when you are yourself. */
    impersonating: { type: Object, default: null },
});

const query = ref('');
const results = ref([]);
const busy = ref(false);
const error = ref('');
const searched = ref(false);

let timer = null;

function csrf() {
    const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

async function search() {
    busy.value = true;
    error.value = '';
    try {
        const r = await fetch(`/dev/users?q=${encodeURIComponent(query.value)}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: AbortSignal.timeout(15000),
        });
        if (!r.ok) throw new Error(`Search failed (${r.status})`);
        const data = await r.json();
        results.value = Array.isArray(data?.users) ? data.users : [];
        searched.value = true;
    } catch (e) {
        error.value = e?.message || 'Could not reach the user list.';
        results.value = [];
    } finally {
        busy.value = false;
    }
}

/* Debounced so typing a name doesn't fire a request per keystroke. */
watch(query, () => {
    if (timer) clearTimeout(timer);
    timer = setTimeout(search, 250);
});

async function post(url, body) {
    return fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': csrf(),
        },
        credentials: 'same-origin',
        body: body ? JSON.stringify(body) : null,
        signal: AbortSignal.timeout(20000),
    });
}

async function become(user) {
    busy.value = true;
    error.value = '';
    try {
        let r = await post(`/dev/impersonate/${user.id}`);
        /* Impersonation is operator-only. Fall back to the passwordless
           session switch so a non-operator can still drive a walkthrough. */
        if (r.status === 403) r = await post('/dev/login-as', { user_id: user.id });
        if (!r.ok) throw new Error(`Could not switch (${r.status})`);
        /* FULL page load, not router.reload(). Logging in rotates the session
           id, and an Inertia partial visit can be answered against the old
           session — the switch appears to succeed and the page comes back as
           whoever you already were. A full load re-sends with the new cookie
           and rebuilds every shared prop, which is what an identity change
           needs. */
        window.location.reload();
    } catch (e) {
        error.value = e?.message || 'Could not switch persona.';
        busy.value = false;
    }
}

async function returnToSelf() {
    busy.value = true;
    error.value = '';
    try {
        const r = await post('/dev/impersonate/stop');
        if (!r.ok) throw new Error(`Could not return (${r.status})`);
        window.location.reload();
    } catch (e) {
        error.value = e?.message || 'Could not return to your own account.';
        busy.value = false;
    }
}

const resultLabel = computed(() => {
    if (busy.value) return 'Searching…';
    if (!searched.value) return 'Type a name or email to find someone.';
    if (!results.value.length) return 'Nobody matched.';
    return `${results.value.length} ${results.value.length === 1 ? 'person' : 'people'}`;
});
</script>

<template>
    <div class="persona">
        <div class="persona-head">
            <label class="persona-label" for="dev-persona-q">Become someone else</label>
            <button
                v-if="impersonating"
                type="button"
                class="persona-return"
                :disabled="busy"
                @click="returnToSelf"
            >
                Return to yourself
            </button>
        </div>

        <input
            id="dev-persona-q"
            v-model="query"
            type="search"
            class="persona-input"
            placeholder="Search by name or email"
            autocomplete="off"
            aria-describedby="dev-persona-status"
        />

        <p id="dev-persona-status" class="persona-status" aria-live="polite">
            {{ error || resultLabel }}
        </p>

        <ul v-if="results.length" class="persona-list">
            <li v-for="u in results" :key="u.id">
                <button type="button" class="persona-pick" :disabled="busy" @click="become(u)">
                    <span class="persona-name">
                        {{ u.display_name || u.name }}
                        <span v-if="u.is_operator" class="persona-op">operator</span>
                    </span>
                    <span class="persona-email">{{ u.email }}</span>
                    <span v-if="u.roles?.length" class="persona-roles">{{ u.roles.join(' · ') }}</span>
                </button>
            </li>
        </ul>
    </div>
</template>

<style scoped>
.persona {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    min-width: 16rem;
    max-width: 26rem;
}
.persona-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-2);
}
.persona-label {
    font-weight: 600;
    font-size: 0.8125rem;
}
.persona-input,
.persona-return,
.persona-pick {
    /* 44px targets — WCAG 2.2 AA pointer target size, and this bar gets used
       on a phone during the walk. */
    min-height: 44px;
    font: inherit;
    border-radius: var(--radius-2, 0.375rem);
    border: 1px solid var(--gov-border, currentColor);
    background: transparent;
    color: inherit;
}
.persona-input {
    padding-inline: var(--space-2);
    width: 100%;
}
.persona-return {
    padding-inline: var(--space-3);
    cursor: pointer;
    font-size: 0.8125rem;
}
.persona-status {
    margin: 0;
    font-size: 0.75rem;
    opacity: 0.85;
}
.persona-list {
    list-style: none;
    margin: 0;
    padding: 0;
    max-height: 15rem;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: var(--space-1, 0.25rem);
}
.persona-pick {
    width: 100%;
    text-align: start;
    padding: var(--space-2);
    cursor: pointer;
    display: flex;
    flex-direction: column;
    gap: 0.1rem;
}
.persona-pick:disabled {
    opacity: 0.6;
    cursor: progress;
}
.persona-name {
    font-weight: 600;
    font-size: 0.8125rem;
}
.persona-op {
    font-size: 0.6875rem;
    font-weight: 400;
    opacity: 0.8;
    border: 1px solid currentColor;
    border-radius: 999px;
    padding: 0 0.35rem;
    margin-inline-start: 0.35rem;
}
.persona-email,
.persona-roles {
    font-size: 0.6875rem;
    opacity: 0.8;
}
</style>
