<script setup>
/**
 * ShellV2/DevChamberCast — cast a chamber, with a mouse. (P4's console, plan D3.)
 *
 * WHY THIS EXISTS. Walking "a bill becomes law" by hand is 58 persona
 * switches. POST /dev/chamber/cast has existed since the playtest controls
 * landed and could only be driven by hand-crafted requests — a capability
 * with zero reachability. This is its screen.
 *
 * ══ THE RULE THAT IS THE WHOLE DESIGN ══
 * IT SUPPLIES BALLOTS. IT DOES NOT SUPPLY OUTCOMES. Every ballot files as a
 * real seated member through the real engine (F-LEG-004); quorum,
 * supermajority and bicameral dual agreement are computed exactly as in
 * production, and the outcome shown below is whatever the ENGINE decided —
 * pinned by ChamberCastIsBallotsOnlyTest. If a vote fails, it fails.
 *
 * The lane filter chooses WHO ballots (which chamber's members), never
 * where votes land — each ballot's lane is derived from the member's own
 * seat. Restricting to one lane is how a playtester watches bicameral dual
 * agreement fail on purpose.
 *
 * Refusals are information, not errors: the Speaker is always refused
 * (neutrality), an already-cast member is refused (one member, one ballot).
 * They render here because hiding them would misreport what was filed.
 */
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { csrfFetch } from '../../lib/csrf';

const props = defineProps({
    /** GET /dev/playtest/state payload, fetched by the parent flyout. */
    state: { type: Object, required: true },
});

const emit = defineEmits(['refresh']);

const voteId = ref('');
const yes = ref(0);
const no = ref(0);
const abstain = ref(0);
const lane = ref('');
const busy = ref(false);
const error = ref('');
const outcome = ref(null);

const votes = computed(() => props.state?.open_votes ?? []);
const picked = computed(() => votes.value.find((v) => v.id === voteId.value) ?? null);
const nothingToCast = computed(() => Number(yes.value) + Number(no.value) + Number(abstain.value) === 0);

function laneLabel(l) {
    return l === 'type_a' ? 'Type A · population-apportioned'
        : l === 'type_b' ? 'Type B · one per constituent'
        : 'whole chamber';
}

async function cast() {
    busy.value = true;
    error.value = '';
    outcome.value = null;
    try {
        const body = {
            vote_id: voteId.value,
            yes: Number(yes.value) || 0,
            no: Number(no.value) || 0,
            abstain: Number(abstain.value) || 0,
        };
        if (lane.value) body.lane = lane.value;

        const r = await csrfFetch('/dev/chamber/cast', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        });
        if (r.status === 404) throw new Error('route not available in this build');
        const data = await r.json();
        if (!r.ok) throw new Error(data?.error || `cast failed (${r.status})`);

        outcome.value = data;
        emit('refresh');
        router.reload({ preserveScroll: true });
    } catch (e) {
        error.value = e?.message || 'Could not cast.';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="chamcast">
        <!-- The gate said no: the server's sentence, verbatim, nothing else. -->
        <p v-if="!state.enabled" class="chamcast-refusal">{{ state.reason }}</p>

        <template v-else>
            <label class="chamcast-label" for="dev-cast-vote">Cast a chamber</label>

            <p v-if="!votes.length" class="chamcast-dim chamcast-note">
                No vote is open. Nothing is waiting to be balloted.
            </p>

            <template v-else>
                <select id="dev-cast-vote" v-model="voteId" class="chamcast-input">
                    <option value="" disabled>Pick an open vote…</option>
                    <option v-for="v in votes" :key="v.id" :value="v.id">
                        {{ v.vote_type }} · {{ v.jurisdiction || 'unscoped' }}
                        {{ v.bicameral ? '· bicameral' : '' }} · {{ v.serving }} serving
                    </option>
                </select>

                <div v-if="picked" class="chamcast-lanes">
                    <p v-for="t in picked.lanes" :key="t.lane" class="chamcast-dim chamcast-note">
                        {{ laneLabel(t.lane) }}: yes {{ t.yes }} · no {{ t.no }} · abstain {{ t.abstain }}
                        — needs {{ t.required_yes }} yes of {{ t.serving }} serving, quorum {{ t.quorum_required }}
                    </p>
                </div>

                <div class="chamcast-counts">
                    <label class="chamcast-count">
                        <span>Yes</span>
                        <input v-model.number="yes" type="number" min="0" max="5000" class="chamcast-input chamcast-input--n" />
                    </label>
                    <label class="chamcast-count">
                        <span>No</span>
                        <input v-model.number="no" type="number" min="0" max="5000" class="chamcast-input chamcast-input--n" />
                    </label>
                    <label class="chamcast-count">
                        <span>Abstain</span>
                        <input v-model.number="abstain" type="number" min="0" max="5000" class="chamcast-input chamcast-input--n" />
                    </label>
                    <label class="chamcast-count">
                        <span>Who ballots</span>
                        <select v-model="lane" class="chamcast-input">
                            <option value="">every seated member</option>
                            <option value="type_a">Type A members only</option>
                            <option value="type_b">Type B members only</option>
                        </select>
                    </label>
                </div>

                <p class="chamcast-dim chamcast-note">
                    Ballots file as the seated members through the real engine — the outcome is the
                    engine's alone. Restrict to one chamber to watch dual agreement fail on purpose.
                </p>

                <button
                    type="button"
                    class="chamcast-btn"
                    :disabled="busy || !voteId || nothingToCast"
                    @click="cast"
                >
                    {{ busy ? 'Filing ballots…' : 'File these ballots' }}
                </button>

                <p class="chamcast-status" aria-live="polite">{{ error }}</p>

                <div v-if="outcome" class="chamcast-result" aria-live="polite">
                    <p class="chamcast-result-head">
                        Filed {{ outcome.cast.yes }}/{{ outcome.cast.no }}/{{ outcome.cast.abstain }}
                        (y/n/a) of {{ outcome.requested.yes }}/{{ outcome.requested.no }}/{{ outcome.requested.abstain }}
                        requested, across {{ outcome.eligible }} eligible member(s).
                    </p>
                    <p v-for="(vals, l) in outcome.tallies" :key="l" class="chamcast-dim chamcast-note">
                        {{ laneLabel(l) }}: yes {{ vals.yes || 0 }} · no {{ vals.no || 0 }} · abstain {{ vals.abstain || 0 }}
                    </p>
                    <ul v-if="outcome.refusals.length" class="chamcast-refusals">
                        <li v-for="ref in outcome.refusals" :key="ref" class="chamcast-dim">refused: {{ ref }}</li>
                    </ul>
                    <p class="chamcast-result-head">
                        The vote is <strong>{{ outcome.status }}</strong><template v-if="outcome.outcome"> — <strong>{{ outcome.outcome }}</strong></template>.
                    </p>
                    <p class="chamcast-dim chamcast-note">{{ outcome.ballots_only }}</p>
                </div>
            </template>
        </template>
    </div>
</template>

<style scoped>
.chamcast {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    min-width: 16rem;
    max-width: 34rem;
    font-size: 0.8125rem;
}
.chamcast-refusal {
    margin: 0;
    padding: var(--space-2);
    border: 1px solid var(--gov-border, currentColor);
    border-radius: var(--radius-2, 0.375rem);
    opacity: 0.9;
}
.chamcast-label {
    font-weight: 600;
}
.chamcast-input,
.chamcast-btn {
    min-height: 44px; /* WCAG 2.2 AA target size */
    font: inherit;
    border-radius: var(--radius-2, 0.375rem);
    border: 1px solid var(--gov-border, currentColor);
    background: transparent;
    color: inherit;
    padding-inline: var(--space-2);
}
.chamcast-input--n {
    width: 5.5rem;
}
.chamcast-btn {
    padding-inline: var(--space-3);
    cursor: pointer;
    font-weight: 600;
}
.chamcast-btn:disabled {
    opacity: 0.55;
    cursor: not-allowed;
}
.chamcast-counts {
    display: flex;
    gap: var(--space-2);
    flex-wrap: wrap;
}
.chamcast-count {
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
    font-size: 0.75rem;
}
.chamcast-status {
    margin: 0;
    min-height: 1em;
    font-size: 0.75rem;
    opacity: 0.85;
}
.chamcast-result {
    display: flex;
    flex-direction: column;
    gap: var(--space-1, 0.25rem);
    padding: var(--space-2);
    border: 1px solid var(--gov-border, currentColor);
    border-radius: var(--radius-2, 0.375rem);
}
.chamcast-result-head {
    margin: 0;
}
.chamcast-refusals {
    margin: 0;
    padding-inline-start: 1.25rem;
    font-size: 0.75rem;
}
.chamcast-dim {
    opacity: 0.75;
}
.chamcast-note {
    margin: 0;
    font-size: 0.75rem;
}
.chamcast-lanes {
    display: flex;
    flex-direction: column;
    gap: 0.1rem;
}
</style>
