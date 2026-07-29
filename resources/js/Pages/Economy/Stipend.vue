<script setup>
/**
 * Economy/Stipend — the civic stipend as a page (design contract:
 * mockups/v3/economy/stipend.html).
 *
 * A DIFFERENTIAL, NOT A SALARY. Everyone associated by residency receives the
 * same floor; on top of it, those carrying certain duties receive a small,
 * capped recognition bump. Pay can never become a qualification for office —
 * the differential grants no seat, no vote, no advantage.
 *
 * READ-ONLY. Every number here is set by law: the change path is the
 * F-LEG-031 dual door (chamber supermajority AND constituent consent), and
 * the "propose a change" control goes to the settings register — it never
 * saves a value from this page.
 *
 * The worked examples are computed server-side by the REAL formula
 * (StipendService::bumpFor) on synthetic role sets — no real person's
 * receipt is derivable from this page. That is the k-anonymity posture:
 * public aggregate, private line.
 */
import { Link } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Card from '@/Components/Ui/Card.vue';
import Banner from '@/Components/Ui/Banner.vue';
import { formatMoney, formatCount, formatWhen } from '@/lib/money.js';

defineOptions({ layout: AppShellV2 });

const props = defineProps({
    currency: { type: Object, default: null },
    stipend: { type: Object, required: true },
    clock: { type: Object, required: true },
    k_anon_floor: { type: Number, required: true },
    /** Synthetic personas through the real formula — never real receipts. */
    examples: { type: Array, default: () => [] },
});

const classLabels = {
    node_operator: {
        label: 'Node operator',
        basis: 'Keeps a node of this world running — the infrastructure duty.',
        who: 'Every node operator, no subset.',
    },
    social_moderator: {
        label: 'Social moderator',
        basis: 'Carries the commons moderation duty.',
        who: 'Every social moderator, no subset.',
    },
    office_holder: {
        label: 'Office holder',
        basis: 'Holds a serving civic office — legislator, executive, judge, board.',
        who: 'Every civic role-holder, no subset. A bump ends the day the duty ends.',
    },
};

const roleLabel = (role) => classLabels[role]?.label ?? role;
</script>

<template>
    <PageScaffold title="Civic stipend">
        <template #intro>
            A differential, not a salary. Everyone associated by residency receives the same floor;
            on top of it, those carrying certain duties receive a small recognition bump. Because
            pay can never become a qualification for office, the differential is add-only, capped,
            and writes nothing but the private ledger — it grants no seat, no vote, no advantage.
        </template>

        <Banner v-if="stipend.enabled === false" tone="info" title="Switched off in this world">
            A legislature turned the stipend off by act. The formula below is what would apply if
            it were switched back on — the switch itself is a legislative decision, not a setting
            anyone edits.
        </Banner>

        <!-- ---------------------------------------------------- formula -->
        <Card as="section" title="How an amount is computed">
            <p class="stipend-formula">
                amount = floor + min( Σ eligible-role bumps, cap )<br />
                = {{ formatMoney(stipend.floor, currency) }} base
                + min( Σ bumps, {{ formatMoney(stipend.cap, currency) }} cap )
            </p>
            <p class="econ-note">
                <strong>Who receives it — residency, and nothing else.</strong> It is the same gate
                that unlocks voting and candidacy: an absolute right, no means test, no
                application, no qualification of any kind.
            </p>
        </Card>

        <!-- ------------------------------------------------------ clock -->
        <Card as="section" title="When it runs">
            <dl class="econ-facts">
                <div>
                    <dt>Cadence</dt>
                    <dd>{{ stipend.interval }}<template v-if="stipend.period_days"> ({{ formatCount(stipend.period_days) }} days)</template></dd>
                </div>
                <div>
                    <dt>Funded by</dt>
                    <dd>{{ stipend.funding_source === 'treasury_draw' ? 'the treasury' : 'new issuance' }}</dd>
                </div>
                <div>
                    <dt>Last run</dt>
                    <dd>{{ clock.last_run ? formatWhen(clock.last_run.ran_at) : 'not yet run in this world' }}</dd>
                </div>
                <div v-if="clock.next_run_estimate">
                    <dt>Next run (estimated)</dt>
                    <dd>{{ formatWhen(clock.next_run_estimate) }}</dd>
                </div>
            </dl>
        </Card>

        <!-- ---------------------------------------------------- classes -->
        <Card as="section" title="The three differential classes">
            <p class="econ-desc">
                A bump attaches to a fact about what a person is doing — never to who they are.
                Stacking them can never exceed the {{ formatMoney(stipend.cap, currency) }} cap.
            </p>
            <div class="stipend-classes">
                <div v-for="(meta, key) in classLabels" :key="key" class="stipend-class">
                    <div class="stipend-class-head">
                        <strong>{{ meta.label }}</strong>
                        <span class="stipend-bump">+{{ formatMoney(stipend.bumps[key], currency) }}</span>
                    </div>
                    <p class="econ-desc">{{ meta.basis }}</p>
                    <p class="econ-meta"><span>Who: {{ meta.who }}</span></p>
                </div>
            </div>
        </Card>

        <!-- ----------------------------------------- values + the change path -->
        <Card as="section" title="The numbers, and how they change">
            <p class="econ-desc">
                The current values, on the record. Nobody edits these on a settings screen: each
                one moves only when <strong>two doors open</strong> — a supermajority of the
                chamber AND the consent of the people it governs. Because the constituents' own
                money is spent, they must agree.
            </p>
            <dl class="econ-facts">
                <div><dt>Residency floor</dt><dd>{{ formatMoney(stipend.floor, currency) }}</dd></div>
                <div><dt>Bump cap (max stacked)</dt><dd>{{ formatMoney(stipend.cap, currency) }}</dd></div>
            </dl>
            <p>
                <Link href="/legislature/settings" class="econ-back">
                    Propose a change — the settings register
                </Link>
            </p>
            <p class="econ-note">
                That drafts a proposal for the chamber (F-LEG-031). It never saves a default.
            </p>
        </Card>

        <!-- --------------------------------------------------- examples -->
        <Card as="section" title="Worked examples">
            <p class="econ-desc">
                Same floor for all; the spread is only the duty differential. These are computed by
                the live formula on example role sets — they are illustrations, not anyone's
                receipt.
            </p>
            <table class="stipend-examples">
                <thead>
                    <tr>
                        <th scope="col">Situation</th>
                        <th scope="col">Duties</th>
                        <th scope="col">Base + bump</th>
                        <th scope="col">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="ex in examples" :key="ex.label">
                        <td>{{ ex.label }}</td>
                        <td>
                            <template v-if="ex.roles.length">{{ ex.roles.map(roleLabel).join(' · ') }}</template>
                            <template v-else>none</template>
                        </td>
                        <td>
                            {{ formatMoney(ex.base, currency) }} + {{ formatMoney(ex.bump, currency) }}
                            <template v-if="ex.capped"> (the cap bites)</template>
                        </td>
                        <td><strong>{{ formatMoney(ex.amount, currency) }}</strong></td>
                    </tr>
                </tbody>
            </table>
        </Card>

        <!-- -------------------------------------------- public vs private -->
        <Card as="section" title="Public aggregate vs private receipt">
            <div class="stipend-split">
                <div>
                    <p class="econ-meta"><span>Public — the aggregate</span></p>
                    <template v-if="clock.last_run">
                        <p>
                            <strong>{{ formatMoney(clock.last_run.total, currency) }}</strong>
                            across {{ formatCount(clock.last_run.recipients) }} recipients.
                        </p>
                        <p v-if="clock.last_run.short_paid" class="econ-note">
                            That run was short-paid: the treasury could not cover it in full, so
                            everyone received the same fraction — never the first N in line.
                        </p>
                    </template>
                    <p v-else>No run has happened yet — the aggregate appears with the first one.</p>
                    <p class="econ-note">
                        Only the total and the head-count publish. A class with fewer than
                        {{ formatCount(k_anon_floor) }} recipients is folded into the general
                        total and never published on its own — a small class would identify its
                        members.
                    </p>
                </div>
                <div>
                    <p class="econ-meta"><span>Private — your receipt</span></p>
                    <p>
                        Each person's own amount writes only to their own private wallet.
                        <Link href="/economy/wallet" class="econ-back">My wallet — see your own line</Link>
                    </p>
                </div>
            </div>
        </Card>
    </PageScaffold>
</template>

<style scoped>
.stipend-formula {
    font-family: var(--font-mono, monospace);
    background: var(--gov-surface-subtle, #eef);
    padding: var(--space-3, 1rem);
    border-radius: 0.5rem;
    margin: 0;
}
.econ-facts {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-3, 1rem);
    margin: 0;
}
.econ-facts > div {
    flex: 1 1 10rem;
}
.econ-facts dt {
    font-size: var(--text-sm, 0.875rem);
    color: var(--gov-fg-muted, #667);
}
.econ-facts dd {
    margin: 0;
    font-size: var(--text-lg, 1.25rem);
    color: var(--gov-fg, #223);
}
.stipend-classes {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr));
    gap: var(--space-3, 1rem);
}
.stipend-class {
    border: 1px solid var(--gov-border, #dde);
    border-radius: 0.5rem;
    padding: var(--space-3, 1rem);
}
.stipend-class-head {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    gap: var(--space-2, 0.5rem);
}
.stipend-bump {
    color: var(--gov-fg, #223);
    font-weight: 600;
}
.stipend-examples {
    inline-size: 100%;
    border-collapse: collapse;
}
.stipend-examples th,
.stipend-examples td {
    text-align: start;
    padding: var(--space-2, 0.5rem);
    border-block-end: 1px solid var(--gov-border, #dde);
}
.stipend-split {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr));
    gap: var(--space-3, 1rem);
}
</style>
