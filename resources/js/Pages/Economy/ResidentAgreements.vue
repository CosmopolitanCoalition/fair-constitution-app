<script setup>
/**
 * Economy/ResidentAgreements — the person-to-person / N-party consent plane
 * (Design Round 2 ③, F-IND-020; the #9 reversal).
 *
 * An agreement between residents, no organization. Art. I: it takes effect
 * ONLY when every party signs — a one-sided contract never takes effect.
 * Parties are shown BY NAME (a signature is a name); this is the consent
 * plane, not the pseudonymous money plane. A clause can never waive a right
 * (Art. I floor) — a redline that tries is refused with a citation.
 */
import { useForm, router } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Card from '@/Components/Ui/Card.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';

defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    agreements: { type: Array, default: () => [] },
    candidates: { type: Array, default: () => [] },
    my_id: { type: String, default: null },
});

const draft = useForm({ title: '', terms: '', signers: [] });
const submit = () => draft.post('/economy/resident-agreements', {
    preserveScroll: true,
    onSuccess: () => draft.reset(),
});

const sign = (id) => router.post(`/economy/resident-agreements/${id}/sign`, {}, { preserveScroll: true });
const resolve = (redlineId, how) => router.post(`/economy/redlines/${redlineId}/${how}`, {}, { preserveScroll: true });

// One lightweight redline composer, keyed by agreement id.
const redline = useForm({ subject_type: 'resident', subject_id: '', clause_id: '', kind: 'edit', body: '', rationale: '' });
const proposeOn = (agreementId, clauseId) => {
    redline.subject_id = agreementId;
    redline.clause_id = clauseId ?? '';
    redline.post('/economy/redlines', { preserveScroll: true, onSuccess: () => { redline.body = ''; redline.rationale = ''; } });
};
</script>

<template>
    <PageScaffold title="Resident agreements">
        <template #intro>
            Agreements between people — no organization involved. Freedom to contract, on a floor no
            clause can lower: an agreement takes effect only when every party has signed, and no term
            may sign away a constitutional right.
        </template>

        <!-- ------------------------------------------------- new agreement -->
        <Card as="section" title="Offer an agreement">
            <form class="ra-form" @submit.prevent="submit">
                <label>Title<input v-model="draft.title" type="text" maxlength="200" required /></label>
                <label>Terms<textarea v-model="draft.terms" rows="3" maxlength="10000" required /></label>
                <fieldset>
                    <legend>Other parties (all must sign)</legend>
                    <p v-if="!candidates.length" class="econ-note">No one else to contract with yet.</p>
                    <label v-for="c in candidates" :key="c.id" class="ra-check">
                        <input v-model="draft.signers" type="checkbox" :value="c.id" /> {{ c.name }}
                    </label>
                </fieldset>
                <p v-if="draft.errors.constitution" class="ra-err">{{ draft.errors.constitution }}</p>
                <button type="submit" :disabled="draft.processing || !draft.signers.length">Offer it</button>
            </form>
        </Card>

        <!-- ---------------------------------------------------- my agreements -->
        <Card v-for="a in agreements" :key="a.id" as="section" :title="a.title">
            <p class="ra-status">
                <StatusBadge>{{ a.status }}</StatusBadge>
                <span v-if="a.is_initiator" class="econ-note">· you offered this</span>
            </p>

            <p class="ra-terms">{{ a.terms }}</p>

            <div class="ra-signers">
                <span v-for="(s, i) in a.signers" :key="i" class="ra-signer" :class="{ signed: s.signed }">
                    {{ s.signed ? '✓' : '○' }} {{ s.name }}<template v-if="s.is_me"> (you)</template>
                </span>
            </div>

            <button v-if="a.can_sign" class="ra-sign" @click="sign(a.id)">Sign this agreement</button>

            <!-- pending redlines -->
            <div v-if="a.redlines.length" class="ra-redlines">
                <h3>Proposed changes</h3>
                <div v-for="r in a.redlines" :key="r.id" class="ra-redline">
                    <p><strong>{{ r.kind }}</strong>: {{ r.body }}</p>
                    <p v-if="r.rationale" class="econ-note">Why: {{ r.rationale }}</p>
                    <div class="ra-redline-acts">
                        <template v-if="r.is_mine">
                            <button @click="resolve(r.id, 'withdraw')">Withdraw</button>
                        </template>
                        <template v-else>
                            <button @click="resolve(r.id, 'accept')">Accept (voids signatures)</button>
                            <button @click="resolve(r.id, 'reject')">Reject</button>
                        </template>
                    </div>
                </div>
            </div>

            <!-- propose a redline on a clause -->
            <details class="ra-propose">
                <summary>Propose a change</summary>
                <div v-for="c in a.clauses" :key="c.id" class="ra-clause">
                    <p class="econ-note">{{ c.heading || 'Clause' }}: {{ c.body }}</p>
                    <div class="ra-propose-row">
                        <select v-model="redline.kind"><option value="edit">Edit</option><option value="strike">Strike</option></select>
                        <input v-model="redline.body" type="text" placeholder="Your language" />
                        <button @click="proposeOn(a.id, c.id)">Propose</button>
                    </div>
                </div>
                <p v-if="redline.errors.constitution" class="ra-err">{{ redline.errors.constitution }}</p>
            </details>
        </Card>

        <p v-if="!agreements.length" class="econ-absent">You are not party to any resident agreements yet.</p>
    </PageScaffold>
</template>

<style scoped>
.ra-form { display: flex; flex-direction: column; gap: var(--space-3, 0.75rem); max-inline-size: 40rem; }
.ra-form label { display: flex; flex-direction: column; gap: var(--space-1, 0.25rem); }
.ra-check { flex-direction: row !important; align-items: center; gap: var(--space-2, 0.5rem); }
.ra-status { display: flex; gap: var(--space-2, 0.5rem); align-items: center; }
.ra-terms { color: var(--gov-fg, #223); }
.ra-signers { display: flex; flex-wrap: wrap; gap: var(--space-3, 1rem); margin-block: var(--space-3, 0.75rem); }
.ra-signer { color: var(--gov-fg-muted, #667); }
.ra-signer.signed { color: var(--gov-success, #262); font-weight: 600; }
.ra-sign { margin-block: var(--space-2, 0.5rem); }
.ra-redlines { margin-block-start: var(--space-3, 1rem); border-block-start: 1px solid var(--gov-border, #dde); padding-block-start: var(--space-3, 0.75rem); }
.ra-redline { margin-block-end: var(--space-3, 0.75rem); }
.ra-redline-acts { display: flex; gap: var(--space-2, 0.5rem); }
.ra-propose { margin-block-start: var(--space-3, 1rem); }
.ra-propose-row { display: flex; gap: var(--space-2, 0.5rem); margin-block-end: var(--space-2, 0.5rem); }
.ra-propose-row input { flex: 1 1 auto; }
.econ-note { font-size: var(--text-sm, 0.875rem); color: var(--gov-fg-muted, #778); }
.econ-absent { color: var(--gov-fg-muted, #667); font-style: italic; padding: var(--space-3, 1rem); background: var(--gov-surface-subtle, #eef); border-radius: 0.5rem; }
.ra-err { color: var(--gov-danger, #b00); font-size: var(--text-sm, 0.875rem); }
</style>
