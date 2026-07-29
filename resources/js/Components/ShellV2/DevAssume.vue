<script setup>
/**
 * ShellV2/DevAssume — pick a place and a role, be that person. (Plan D4.)
 *
 * The composed act the mockup's demo bar promises: what used to be three
 * manual moves (find a user, maybe grant residency, log in as them) is
 * one POST to /dev/assume. The server does FIND → (maybe dev-relocate) →
 * BECOME; its two rules — never creates users, never seats anyone — mean
 * a refusal here is an ANSWER: it names the journey to walk first.
 *
 * Identity change ⇒ FULL page load after (the DevPersonaSwitcher rule:
 * the session id rotates, and an Inertia partial visit can be answered
 * against the old session).
 */
import { computed, ref } from 'vue';
import { csrfFetch } from '../../lib/csrf';

const props = defineProps({
    /** GET /dev/playtest/state payload, fetched by the parent. */
    state: { type: Object, required: true },
});

const ROLES = [
    { code: 'R-04', label: 'Resident / voter (relocates one if needed)' },
    { code: 'R-06', label: 'Candidate' },
    { code: 'R-08', label: 'Election board member' },
    { code: 'R-09', label: 'Legislator' },
    { code: 'R-10', label: 'Speaker' },
    { code: 'R-19', label: 'Judge (appointed court)' },
    { code: 'R-20', label: 'Judge (elected court)' },
    { code: 'R-21', label: 'Advocate' },
];

const place = ref('');
const role = ref('R-04');
const busy = ref(false);
const note = ref('');
const failed = ref(false);

const ready = computed(() => place.value.trim() !== '' && role.value !== '');

async function assume() {
    busy.value = true;
    note.value = '';
    failed.value = false;
    try {
        const r = await csrfFetch('/dev/assume', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ jurisdiction: place.value.trim(), role: role.value }),
        });
        if (r.status === 404 && !(await r.clone().json().catch(() => null))) {
            throw new Error('route not available in this build');
        }
        const data = await r.json();
        if (!r.ok) throw new Error(data?.error || `assume failed (${r.status})`);

        note.value = `Becoming ${data.user.name} (${data.how} · ${data.role} in ${data.jurisdiction.name})…`;
        /* Identity changed server-side already — full load, never a partial. */
        window.location.reload();
    } catch (e) {
        failed.value = true;
        note.value = e?.message || 'Could not assume.';
        busy.value = false;
    }
}
</script>

<template>
    <div class="assume">
        <!-- The gate said no: the server's sentence, verbatim, nothing else. -->
        <p v-if="!state.enabled" class="assume-refusal">{{ state.reason }}</p>

        <template v-else>
            <label class="assume-label" for="dev-assume-place">Assume a resident or role of a place</label>
            <div class="assume-row">
                <input
                    id="dev-assume-place"
                    v-model="place"
                    type="text"
                    class="assume-input"
                    placeholder="Place slug or id (e.g. smr-1-san-marino)"
                    autocomplete="off"
                />
                <select v-model="role" class="assume-input" aria-label="Role to assume">
                    <option v-for="r in ROLES" :key="r.code" :value="r.code">{{ r.code }} — {{ r.label }}</option>
                </select>
                <button type="button" class="assume-btn" :disabled="busy || !ready" @click="assume">
                    {{ busy ? 'Assuming…' : 'Assume' }}
                </button>
            </div>
            <p class="assume-status" :class="{ 'assume-status--refused': failed }" aria-live="polite">
                {{ note }}
            </p>
        </template>
    </div>
</template>

<style scoped>
.assume {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    min-width: 16rem;
    max-width: 34rem;
    font-size: 0.8125rem;
}
.assume-refusal {
    margin: 0;
    padding: var(--space-2);
    border: 1px solid var(--gov-border, currentColor);
    border-radius: var(--radius-2, 0.375rem);
    opacity: 0.9;
}
.assume-label {
    font-weight: 600;
}
.assume-row {
    display: flex;
    gap: var(--space-2);
    flex-wrap: wrap;
}
.assume-input,
.assume-btn {
    min-height: 44px; /* WCAG 2.2 AA target size */
    font: inherit;
    border-radius: var(--radius-2, 0.375rem);
    border: 1px solid var(--gov-border, currentColor);
    background: transparent;
    color: inherit;
    padding-inline: var(--space-2);
}
.assume-input {
    flex: 1 1 12rem;
}
.assume-btn {
    padding-inline: var(--space-3);
    cursor: pointer;
    font-weight: 600;
}
.assume-btn:disabled {
    opacity: 0.55;
    cursor: not-allowed;
}
.assume-status {
    margin: 0;
    min-height: 1em;
    font-size: 0.75rem;
    opacity: 0.85;
}
.assume-status--refused {
    opacity: 1;
}
</style>
