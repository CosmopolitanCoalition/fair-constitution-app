<script setup>
/**
 * Economy/JointLedgers — co-owned accounts (design contract:
 * mockups/v3/economy/joint-ledgers.html).
 *
 * A joint ledger is a co-owned account: its balance belongs to more than
 * one party, and no movement leaves it until the required co-owners agree.
 * The money lives in an ordinary ESCROW account on the one public ledger —
 * funding it is a plain transfer to that account; a movement settles as a
 * plain transfer out of it, gated on the approval rule.
 *
 * PRIVACY: a public ledger (a jurisdiction shared fund) is anyone's to
 * watch; a private one is readable only by its co-owners — like a ballot.
 * This is the MONEY plane, so parties render as ACCOUNTS, never people.
 *
 * Every write files F-IND-023 through the ConstitutionalEngine (the
 * joint_open / joint_propose / joint_approve actions) — there is no
 * economy write API, and a refusal (non-party, double approval, an
 * underfunded escrow) renders as the constitutional answer it is.
 */
import { computed } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Card from '@/Components/Ui/Card.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Field from '@/Components/Ui/Field.vue';
import FormChip from '@/Components/Ui/FormChip.vue';
import { formatMoney, formatWhen, shortId } from '@/lib/money.js';

defineOptions({ layout: AppShellV2 });

const props = defineProps({
    currency: { type: Object, default: null },
    ledgers: { type: Array, default: () => [] },
    can_open: { type: Boolean, default: false },
    my_account_id: { type: String, default: null },
});

const page = usePage();
const flashStatus = computed(() => page.props.flash?.status ?? null);
const constitutionError = computed(() => page.props.errors?.constitution ?? null);

// joint_open. Co-owners arrive as ACCOUNT ids — the same way a transfer
// names its recipient. The opener is always a party; the server adds them.
const open = useForm({
    name: '',
    purpose: '',
    approval_rule: 'all',
    public: false,
    parties_raw: '',
});

function submitOpen() {
    open.transform((data) => ({
        name: data.name,
        purpose: data.purpose,
        approval_rule: data.approval_rule,
        public: data.public,
        party_account_ids: data.parties_raw
            .split(/[\s,]+/)
            .map((s) => s.trim())
            .filter(Boolean),
    })).post('/economy/joint-ledgers', {
        preserveScroll: true,
        onSuccess: () => open.reset(),
    });
}

// joint_propose — one form per ledger, keyed by ledger id.
const propose = useForm({ ledger_id: '', to_account_id: '', amount: '', memo: '' });

function submitPropose(ledgerId) {
    propose.ledger_id = ledgerId;
    propose.post(`/economy/joint-ledgers/${ledgerId}/propose`, {
        preserveScroll: true,
        onSuccess: () => propose.reset(),
    });
}

const approving = useForm({});

function submitApprove(movementId) {
    approving.post(`/economy/joint-movements/${movementId}/approve`, {
        preserveScroll: true,
    });
}
</script>

<template>
    <PageScaffold title="Joint ledgers">
        <template #intro>
            A joint ledger is a co-owned account: its balance belongs to more than one party, and
            no movement leaves it until every required co-owner agrees. One signer can never move
            shared money alone — a movement is proposed, waits, and settles only when the ledger's
            approval rule is met.
        </template>

        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
        <Banner v-if="constitutionError" tone="emergency">{{ constitutionError }}</Banner>

        <Card as="section" title="How a joint movement settles">
            <ol class="jl-how">
                <li>
                    <strong>Propose.</strong> Any co-owner proposes a movement out — a payment, a
                    transfer, a drawdown. Proposing counts as their signature.
                </li>
                <li>
                    <strong>Wait.</strong> The movement is held while the remaining co-owners
                    decide. Each approval is on the record.
                </li>
                <li>
                    <strong>Settle.</strong> The approval that meets the rule — all signers, or a
                    majority, as agreed at opening — moves the money in the same act.
                </li>
            </ol>
            <p class="econ-note">
                Money enters a joint ledger the ordinary way: a plain transfer to its account. The
                ledger holds and moves the unit; it never issues it.
            </p>
        </Card>

        <Card as="section" title="Your joint ledgers">
            <p v-if="!ledgers.length" class="econ-empty">
                None yet — you are not a co-owner of any joint ledger, and no public one exists in
                this world.
            </p>

            <article v-for="l in ledgers" :key="l.id" class="jl-card">
                <div class="jl-head">
                    <div>
                        <h3>{{ l.name }}</h3>
                        <p v-if="l.purpose" class="econ-desc">{{ l.purpose }}</p>
                    </div>
                    <span class="jl-vis">{{ l.public ? 'Public ledger' : 'Private ledger' }}</span>
                </div>

                <p class="jl-balance">
                    <strong>{{ formatMoney(l.balance, currency) }}</strong>
                    <span class="econ-note">
                        · rule: {{ l.approval_rule === 'all' ? 'every signer' : 'a majority of signers' }}
                    </span>
                </p>

                <p v-if="!l.public" class="econ-note">
                    A private ledger is readable only by its co-owners — like a ballot.
                </p>

                <p class="econ-meta">
                    <span>Co-owners (signers):</span>
                    <span v-for="p in l.parties" :key="p.account_id" class="mono">
                        {{ shortId(p.account_id) }}<template v-if="p.is_me"> (you)</template>
                    </span>
                </p>

                <p v-if="l.is_party && l.escrow_account_id" class="econ-note">
                    Fund it: send a plain transfer to account
                    <span class="mono">{{ l.escrow_account_id }}</span> from your wallet.
                </p>

                <div class="jl-movements">
                    <h4>Movements</h4>
                    <p v-if="!l.movements.length" class="econ-note">
                        No movements yet. Any new one will need
                        {{ l.approval_rule === 'all' ? 'every signer' : 'a majority' }} to agree.
                    </p>
                    <div v-for="m in l.movements" :key="m.id" class="jl-movement">
                        <p>
                            <strong>{{ formatMoney(m.amount, currency) }}</strong>
                            to <span class="mono">{{ shortId(m.to_account_id) }}</span>
                            <template v-if="m.memo"> — {{ m.memo }}</template>
                        </p>
                        <p class="econ-note">
                            <template v-if="m.status === 'pending'">
                                {{ m.approvals }} of {{ m.needed }} agreed — held until the
                                agreement is complete.
                                <template v-if="m.i_approved"> Your signature is on it.</template>
                            </template>
                            <template v-else-if="m.status === 'settled'">
                                Settled {{ formatWhen(m.at) }} — the agreement completed and the
                                money moved.
                            </template>
                            <template v-else>{{ m.status }}</template>
                        </p>
                        <Btn
                            v-if="m.can_approve"
                            size="sm"
                            :disabled="approving.processing"
                            @click="submitApprove(m.id)"
                        >
                            Approve this movement
                        </Btn>
                    </div>
                </div>

                <form v-if="l.is_party" class="econ-form jl-propose" @submit.prevent="submitPropose(l.id)">
                    <h4>Propose a movement <FormChip form-id="F-IND-023" name="Joint-Ledger Movement" /></h4>
                    <Field label="To account" :error="propose.errors.to_account_id">
                        <template #control="{ id, describedBy }">
                            <input
                                :id="id"
                                v-model="propose.to_account_id"
                                :aria-describedby="describedBy"
                                type="text"
                                placeholder="The recipient's account id"
                            />
                        </template>
                    </Field>
                    <Field label="Amount" :error="propose.errors.amount">
                        <template #control="{ id, describedBy }">
                            <input
                                :id="id"
                                v-model="propose.amount"
                                :aria-describedby="describedBy"
                                type="text"
                                inputmode="decimal"
                                placeholder="0.00"
                            />
                        </template>
                    </Field>
                    <Field label="What for (optional)" :error="propose.errors.memo">
                        <template #control="{ id, describedBy }">
                            <input
                                :id="id"
                                v-model="propose.memo"
                                :aria-describedby="describedBy"
                                type="text"
                                maxlength="240"
                            />
                        </template>
                    </Field>
                    <Btn type="submit" :disabled="propose.processing">Propose</Btn>
                    <p class="econ-note">
                        Proposing signs it. It settles only when the rule is met — your signature
                        alone moves nothing.
                    </p>
                </form>
            </article>
        </Card>

        <Card v-if="can_open" as="section">
            <template #title>
                <span class="econ-card-title">
                    New joint ledger <FormChip form-id="F-IND-023" name="Funds Transfer · Joint Ledger" />
                </span>
            </template>
            <p class="econ-desc">
                Name the co-owners and the approval rule up front. You are always a signer of a
                ledger you open; the other co-owners join as their account ids — the same way a
                transfer names its recipient.
            </p>
            <form class="econ-form" @submit.prevent="submitOpen">
                <Field label="Name" :error="open.errors.name">
                    <template #control="{ id, describedBy }">
                        <input :id="id" v-model="open.name" :aria-describedby="describedBy" type="text" maxlength="160" />
                    </template>
                </Field>
                <Field label="Purpose (optional)" :error="open.errors.purpose">
                    <template #control="{ id, describedBy }">
                        <input :id="id" v-model="open.purpose" :aria-describedby="describedBy" type="text" maxlength="500" />
                    </template>
                </Field>
                <Field label="Co-owners' account ids (comma or space separated)" :error="open.errors.party_account_ids">
                    <template #control="{ id, describedBy }">
                        <textarea :id="id" v-model="open.parties_raw" :aria-describedby="describedBy" rows="2"></textarea>
                    </template>
                </Field>
                <Field label="Approval rule" :error="open.errors.approval_rule">
                    <template #control="{ id, describedBy }">
                        <select :id="id" v-model="open.approval_rule" :aria-describedby="describedBy">
                            <option value="all">Every signer must agree</option>
                            <option value="majority">A majority of signers</option>
                        </select>
                    </template>
                </Field>
                <label class="jl-public">
                    <input v-model="open.public" type="checkbox" />
                    Public ledger — anyone may watch it (a jurisdiction-style shared fund)
                </label>
                <Btn type="submit" :disabled="open.processing">Open the ledger</Btn>
            </form>
        </Card>

        <Card as="section" title="The rails that hold a joint ledger">
            <ul class="jl-rails">
                <li>
                    <strong>No movement without agreement.</strong> A single co-owner can never move
                    shared money — the movement waits until the approval rule is met.
                </li>
                <li>
                    <strong>Freedom to contract, with a floor.</strong> Parties set their own rule
                    and purpose, but no clause may waive a right.
                </li>
                <li>
                    <strong>One set of rails.</strong> The balance lives in an ordinary account on
                    the public hash-chained ledger; funding and settlement are ordinary balanced
                    transfers. There is no special joint money.
                </li>
                <li>
                    <strong>No overdraft.</strong> An underfunded ledger refuses a movement the
                    same way a wallet refuses an overdraft.
                </li>
                <li>
                    <strong>Currency stays root-reserved.</strong> A joint ledger holds and moves
                    the unit; it never issues it.
                </li>
            </ul>
        </Card>
    </PageScaffold>
</template>

<style scoped>
.jl-how {
    margin: 0;
    padding-inline-start: 1.25rem;
    display: flex;
    flex-direction: column;
    gap: var(--space-2, 0.5rem);
}
.jl-card {
    border: 1px solid var(--gov-border, #dde);
    border-radius: 0.5rem;
    padding: var(--space-3, 1rem);
    margin-block-end: var(--space-3, 1rem);
}
.jl-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: var(--space-2, 0.5rem);
    flex-wrap: wrap;
}
.jl-head h3 {
    margin: 0;
    color: var(--gov-fg, #223);
}
.jl-vis {
    font-size: var(--text-sm, 0.875rem);
    color: var(--gov-fg-muted, #667);
}
.jl-balance {
    margin-block: var(--space-2, 0.5rem);
    font-size: var(--text-lg, 1.25rem);
}
.jl-movements h4,
.jl-propose h4 {
    margin: var(--space-3, 1rem) 0 var(--space-2, 0.5rem);
    color: var(--gov-fg, #223);
}
.jl-movement {
    border-block-start: 1px solid var(--gov-border, #dde);
    padding-block: var(--space-2, 0.5rem);
}
.jl-movement p {
    margin: 0;
}
.jl-public {
    display: flex;
    align-items: center;
    gap: var(--space-2, 0.5rem);
}
.jl-rails {
    margin: 0;
    padding-inline-start: 1.25rem;
    display: flex;
    flex-direction: column;
    gap: var(--space-2, 0.5rem);
}
.mono {
    font-family: var(--font-mono, ui-monospace, monospace);
}
</style>
