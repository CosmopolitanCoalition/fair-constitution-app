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
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Card from '@/Components/Ui/Card.vue';
import Banner from '@/Components/Ui/Banner.vue';
import { formatMoney, formatCount, formatWhen as formatWhenRaw } from '@/lib/money.js';

defineOptions({ layout: AppShellV2 });
const { t, locale } = useI18n();
const formatWhen = (iso) => formatWhenRaw(iso, locale.value);

const props = defineProps({
    currency: { type: Object, default: null },
    stipend: { type: Object, required: true },
    clock: { type: Object, required: true },
    k_anon_floor: { type: Number, required: true },
    /** Synthetic personas through the real formula — never real receipts. */
    examples: { type: Array, default: () => [] },
});

const classLabels = computed(() => ({
    node_operator: {
        label: t('c_economy.stipend.node_operator_label', 'Node operator'),
        basis: t('c_economy.stipend.node_operator_basis', 'Keeps a node of this world running — the infrastructure duty.'),
        who: t('c_economy.stipend.node_operator_who', 'Every node operator, no subset.'),
    },
    social_moderator: {
        label: t('c_economy.stipend.social_moderator_label', 'Social moderator'),
        basis: t('c_economy.stipend.social_moderator_basis', 'Carries the commons moderation duty.'),
        who: t('c_economy.stipend.social_moderator_who', 'Every social moderator, no subset.'),
    },
    office_holder: {
        label: t('c_economy.stipend.office_holder_label', 'Office holder'),
        basis: t('c_economy.stipend.office_holder_basis', 'Holds a serving civic office — legislator, executive, judge, board.'),
        who: t('c_economy.stipend.office_holder_who', 'Every civic role-holder, no subset. A bump ends the day the duty ends.'),
    },
}));

const roleLabel = (role) => classLabels.value[role]?.label ?? role;
</script>

<template>
    <PageScaffold :title="t('c_economy.stipend.title', 'Civic stipend')">
        <template #intro>
            {{ t('c_economy.stipend.intro', 'A differential, not a salary. Everyone associated by residency receives the same floor; on top of it, those carrying certain duties receive a small recognition bump. Because pay can never become a qualification for office, the differential is add-only, capped, and writes nothing but the private ledger — it grants no seat, no vote, no advantage.') }}
        </template>

        <Banner v-if="stipend.enabled === false" tone="info" :title="t('c_economy.stipend.switched_off_title', 'Switched off in this world')">
            {{ t('c_economy.stipend.switched_off_body', 'A legislature turned the stipend off by act. The formula below is what would apply if it were switched back on — the switch itself is a legislative decision, not a setting anyone edits.') }}
        </Banner>

        <!-- ---------------------------------------------------- formula -->
        <Card as="section" :title="t('c_economy.stipend.how_computed', 'How an amount is computed')">
            <p class="stipend-formula">
                {{ t('c_economy.stipend.formula_line1', 'amount = floor + min( Σ eligible-role bumps, cap )') }}<br />
                {{ t('c_economy.stipend.formula_line2', { floor: formatMoney(stipend.floor, currency), cap: formatMoney(stipend.cap, currency) }) }}
            </p>
            <p class="econ-note">
                <strong>{{ t('c_economy.stipend.who_receives_strong', 'Who receives it — residency, and nothing else.') }}</strong>{{ t('c_economy.stipend.who_receives_body', ' It is the same gate that unlocks voting and candidacy: an absolute right, no means test, no application, no qualification of any kind.') }}
            </p>
        </Card>

        <!-- ------------------------------------------------------ clock -->
        <Card as="section" :title="t('c_economy.stipend.when_runs', 'When it runs')">
            <dl class="econ-facts">
                <div>
                    <dt>{{ t('c_economy.stipend.cadence', 'Cadence') }}</dt>
                    <dd>{{ stipend.interval }}<template v-if="stipend.period_days">{{ t('c_economy.stipend.period_days', { count: formatCount(stipend.period_days) }) }}</template></dd>
                </div>
                <div>
                    <dt>{{ t('c_economy.stipend.funded_by', 'Funded by') }}</dt>
                    <dd>{{ stipend.funding_source === 'treasury_draw' ? t('c_economy.stipend.funding_treasury', 'the treasury') : t('c_economy.stipend.funding_issuance', 'new issuance') }}</dd>
                </div>
                <div>
                    <dt>{{ t('c_economy.stipend.last_run', 'Last run') }}</dt>
                    <dd>{{ clock.last_run ? formatWhen(clock.last_run.ran_at) : t('c_economy.stipend.not_yet_run', 'not yet run in this world') }}</dd>
                </div>
                <div v-if="clock.next_run_estimate">
                    <dt>{{ t('c_economy.stipend.next_run_estimated', 'Next run (estimated)') }}</dt>
                    <dd>{{ formatWhen(clock.next_run_estimate) }}</dd>
                </div>
            </dl>
        </Card>

        <!-- ---------------------------------------------------- classes -->
        <Card as="section" :title="t('c_economy.stipend.classes_title', 'The three differential classes')">
            <p class="econ-desc">
                {{ t('c_economy.stipend.classes_desc', { cap: formatMoney(stipend.cap, currency) }) }}
            </p>
            <div class="stipend-classes">
                <div v-for="(meta, key) in classLabels" :key="key" class="stipend-class">
                    <div class="stipend-class-head">
                        <strong>{{ meta.label }}</strong>
                        <span class="stipend-bump">+{{ formatMoney(stipend.bumps[key], currency) }}</span>
                    </div>
                    <p class="econ-desc">{{ meta.basis }}</p>
                    <p class="econ-meta"><span>{{ t('c_economy.stipend.who_prefix', { who: meta.who }) }}</span></p>
                </div>
            </div>
        </Card>

        <!-- ----------------------------------------- values + the change path -->
        <Card as="section" :title="t('c_economy.stipend.numbers_title', 'The numbers, and how they change')">
            <p class="econ-desc">
                {{ t('c_economy.stipend.numbers_desc_before', 'The current values, on the record. Nobody edits these on a settings screen: each one moves only when') }} <strong>{{ t('c_economy.stipend.numbers_desc_strong', 'two doors open') }}</strong> {{ t('c_economy.stipend.numbers_desc_after', '— a supermajority of the chamber AND the consent of the people it governs. Because the constituents\' own money is spent, they must agree.') }}
            </p>
            <dl class="econ-facts">
                <div><dt>{{ t('c_economy.stipend.residency_floor', 'Residency floor') }}</dt><dd>{{ formatMoney(stipend.floor, currency) }}</dd></div>
                <div><dt>{{ t('c_economy.stipend.bump_cap', 'Bump cap (max stacked)') }}</dt><dd>{{ formatMoney(stipend.cap, currency) }}</dd></div>
            </dl>
            <p>
                <Link href="/legislature/settings" class="econ-back prose-link">
                    {{ t('c_economy.stipend.propose_change', 'Propose a change — the settings register') }}
                </Link>
            </p>
            <p class="econ-note">
                {{ t('c_economy.stipend.propose_note', 'That drafts a proposal for the chamber (F-LEG-031). It never saves a default.') }}
            </p>
        </Card>

        <!-- --------------------------------------------------- examples -->
        <Card as="section" :title="t('c_economy.stipend.examples_title', 'Worked examples')">
            <p class="econ-desc">
                {{ t('c_economy.stipend.examples_desc', 'Same floor for all; the spread is only the duty differential. These are computed by the live formula on example role sets — they are illustrations, not anyone\'s receipt.') }}
            </p>
            <table class="stipend-examples">
                <thead>
                    <tr>
                        <th scope="col">{{ t('c_economy.stipend.th_situation', 'Situation') }}</th>
                        <th scope="col">{{ t('c_economy.stipend.th_duties', 'Duties') }}</th>
                        <th scope="col">{{ t('c_economy.stipend.th_base_bump', 'Base + bump') }}</th>
                        <th scope="col">{{ t('c_economy.stipend.th_amount', 'Amount') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="ex in examples" :key="ex.label">
                        <td>{{ ex.label }}</td>
                        <td>
                            <template v-if="ex.roles.length">{{ ex.roles.map(roleLabel).join(' · ') }}</template>
                            <template v-else>{{ t('c_economy.stipend.none', 'none') }}</template>
                        </td>
                        <td>
                            {{ formatMoney(ex.base, currency) }} + {{ formatMoney(ex.bump, currency) }}<template v-if="ex.capped">{{ t('c_economy.stipend.cap_bites', ' (the cap bites)') }}</template>
                        </td>
                        <td><strong>{{ formatMoney(ex.amount, currency) }}</strong></td>
                    </tr>
                </tbody>
            </table>
        </Card>

        <!-- -------------------------------------------- public vs private -->
        <Card as="section" :title="t('c_economy.stipend.public_private_title', 'Public aggregate vs private receipt')">
            <div class="stipend-split">
                <div>
                    <p class="econ-meta"><span>{{ t('c_economy.stipend.public_aggregate', 'Public — the aggregate') }}</span></p>
                    <template v-if="clock.last_run">
                        <p>
                            <strong>{{ formatMoney(clock.last_run.total, currency) }}</strong>
                            {{ t('c_economy.stipend.across_recipients', { count: formatCount(clock.last_run.recipients) }) }}
                        </p>
                        <p v-if="clock.last_run.short_paid" class="econ-note">
                            {{ t('c_economy.stipend.short_paid', 'That run was short-paid: the treasury could not cover it in full, so everyone received the same fraction — never the first N in line.') }}
                        </p>
                    </template>
                    <p v-else>{{ t('c_economy.stipend.no_run_yet', 'No run has happened yet — the aggregate appears with the first one.') }}</p>
                    <p class="econ-note">
                        {{ t('c_economy.stipend.k_anon_note', { floor: formatCount(k_anon_floor) }) }}
                    </p>
                </div>
                <div>
                    <p class="econ-meta"><span>{{ t('c_economy.stipend.private_receipt', 'Private — your receipt') }}</span></p>
                    <p>
                        {{ t('c_economy.stipend.private_receipt_body', 'Each person\'s own amount writes only to their own private wallet.') }}
                        <Link href="/economy/wallet" class="econ-back prose-link">{{ t('c_economy.stipend.my_wallet_line', 'My wallet — see your own line') }}</Link>
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
