<script setup>
/**
 * Economy/RequestDetail — one work posting, end to end (design contract:
 * mockups/v3/economy/request-detail.html).
 *
 * THE PAGE'S ONE JOB: show what accepting an application actually does — the
 * F-IND-014 chain down to the board seat it can earn. The thresholds in the
 * co-determination panel are LIVE, resolved from the amendable settings —
 * never the 100/2000 literals, because those values legislate.
 *
 * Applying files F-IND-019 through the ConstitutionalEngine — there is no
 * economy write API. A refusal (closed posting, duplicate application) comes
 * back as a constitutional rejection carrying its citation, and the page
 * renders it as the answer it is.
 */
import { computed } from 'vue';
import { useForm, usePage, Link } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Card from '@/Components/Ui/Card.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Field from '@/Components/Ui/Field.vue';
import FormChip from '@/Components/Ui/FormChip.vue';
import { formatMoney, formatCount, formatWhen } from '@/lib/money.js';

defineOptions({ layout: AppShellV2 });

const props = defineProps({
    currency: { type: Object, default: null },
    posting: { type: Object, required: true },
    /** Live thresholds + headcount — resolved from settings, never literals. */
    codetermination: { type: Object, required: true },
    can_apply: { type: Boolean, default: false },
    has_applied: { type: Boolean, default: false },
});

const page = usePage();
const flashStatus = computed(() => page.props.flash?.status ?? null);
const constitutionError = computed(() => page.props.errors?.constitution ?? null);

// F-IND-019. The note is optional; the application is account-scoped — the
// organization sees an account until a hire makes a contract of it.
const apply = useForm({ note: '' });

function submitApply() {
    apply.post(`/economy/requests/${props.posting.id}/apply`, {
        preserveScroll: true,
        onSuccess: () => apply.reset(),
    });
}

const steps = computed(() => [
    {
        n: 1,
        title: 'You apply',
        actor: 'The worker',
        body: 'Any associated resident may apply. There is no means test and no qualification gate — applying never touches a civic right.',
        chip: { id: 'F-IND-019', name: 'Work Application' },
    },
    {
        n: 2,
        title: 'The organization accepts',
        actor: props.posting.org_name,
        body: 'The hiring organization reviews applications and accepts. Acceptance is the trigger — nothing binds until both sides act.',
        chip: null,
    },
    {
        n: 3,
        title: 'The work agreement is recorded',
        actor: 'The game',
        body: 'On acceptance the work agreement is recorded with both signatures — a one-sided contract never takes effect.',
        chip: { id: 'F-IND-014', name: 'Worker Registration' },
    },
    {
        n: 4,
        title: 'The hire counts toward co-determination',
        actor: 'Headcount',
        body:
            `The new recurring labor contract increments the organization's worker headcount. ` +
            `A single hire can cross the ${formatCount(props.codetermination.first_seat_at)}-employee threshold ` +
            `that earns workers their first seat on the board.`,
        chip: null,
    },
]);
</script>

<template>
    <PageScaffold title="Work posting">
        <template #intro>
            One job on the board, end to end: the rate, the organization, and exactly what happens
            when an application is accepted — down to the record it creates and the board seat it
            can earn.
        </template>

        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
        <Banner v-if="constitutionError" tone="emergency">{{ constitutionError }}</Banner>

        <p class="econ-note">
            Your application and contract terms are private — only you and the organization can
            read them. Publicly, only the anonymous headcount total is visible.
        </p>

        <!-- ------------------------------------------------------- hero -->
        <Card as="section" inset>
            <div class="req-hero">
                <div>
                    <p class="req-kind">Work posting</p>
                    <h2 class="req-title">{{ posting.title }}</h2>
                    <p class="req-org">{{ posting.org_name }}</p>
                </div>
                <div class="req-rate">
                    <strong v-if="posting.rate">{{ formatMoney(posting.rate, currency) }}</strong>
                    <strong v-else>Rate on agreement</strong>
                    <span>recurring labor<template v-if="currency"> · {{ currency.code }}</template></span>
                </div>
            </div>
            <p class="econ-meta">
                <span>{{ posting.status === 'open' ? 'Hiring now' : 'No longer open' }}</span>
                <span>{{ formatCount(posting.applications) }} applied</span>
                <span>posted {{ formatWhen(posting.at) }}</span>
            </p>
            <p class="econ-desc">{{ posting.terms }}</p>
        </Card>

        <!-- ---------------------------------------------------- parties -->
        <Card as="section" title="The two parties">
            <p>
                <strong>The applying resident</strong> (worker) and
                <strong>{{ posting.org_name }}</strong> (organization).
            </p>
            <p class="econ-note">
                Both parties sign. The worker and the organization each consent on the record — a
                one-sided contract never takes effect. No clause may waive a right.
            </p>
        </Card>

        <!-- -------------------------------------------------- lifecycle -->
        <Card as="section" title="The application lifecycle">
            <p class="econ-desc">
                From applying to a seated worker representative — four acts, each on the record.
            </p>
            <ol class="req-steps">
                <li v-for="s in steps" :key="s.n">
                    <div class="req-step-head">
                        <span class="req-step-n" aria-hidden="true">{{ s.n }}</span>
                        <strong>{{ s.title }}</strong>
                        <span class="req-step-actor">{{ s.actor }}</span>
                        <FormChip v-if="s.chip" :form-id="s.chip.id" :name="s.chip.name" />
                    </div>
                    <p>{{ s.body }}</p>
                </li>
            </ol>
        </Card>

        <!-- --------------------------------------------- codetermination -->
        <Card as="section" title="Why a hire matters — the co-determination threshold">
            <p>
                Each accepted application adds a recurring labor contract to the organization's
                worker count. The constitution earns workers board representation by headcount —
                the math is locked; the thresholds below are this jurisdiction's enacted values.
            </p>
            <ul class="req-thresholds">
                <li>
                    <strong>First worker seat at {{ formatCount(codetermination.first_seat_at) }} employees.</strong>
                    The hire that crosses it unlocks the organization's first board seat for workers.
                </li>
                <li>
                    <strong>Worker / owner parity at {{ formatCount(codetermination.parity_at) }} employees.</strong>
                    Above parity, workers and owners hold equal board power.
                </li>
                <li>
                    <strong>No clause can waive it.</strong>
                    A labor contract cannot bargain away the worker's representation right.
                </li>
            </ul>
            <p class="econ-meta">
                <span>This organization today: {{ formatCount(codetermination.headcount) }} active workers</span>
            </p>
        </Card>

        <!-- ------------------------------------------------------ apply -->
        <Card v-if="can_apply" as="section">
            <template #title>
                <span class="econ-card-title">Apply <FormChip form-id="F-IND-019" name="Work Application" /></span>
            </template>
            <form class="econ-form" @submit.prevent="submitApply">
                <Field label="A note to the organization (optional)" :error="apply.errors.note">
                    <template #control="{ id, describedBy }">
                        <textarea
                            :id="id"
                            v-model="apply.note"
                            :aria-describedby="describedBy"
                            rows="3"
                            maxlength="500"
                            placeholder="Why you — in your own words. The organization reads this; nobody else does."
                        ></textarea>
                    </template>
                </Field>
                <Btn type="submit" :disabled="apply.processing">Apply for this work</Btn>
                <p class="econ-note">
                    Applying commits nobody. If the organization accepts, the work agreement is
                    recorded with both signatures — never one.
                </p>
            </form>
        </Card>

        <Card v-else-if="has_applied" as="section" title="You have applied">
            <p>
                Your application is on the record. The organization decides — if it accepts, the
                work agreement is recorded with both signatures.
            </p>
        </Card>

        <Card v-else-if="posting.status !== 'open'" as="section" title="This posting is closed">
            <p>It is no longer taking applications. Open postings live on the market's Work tab.</p>
        </Card>

        <p>
            <Link href="/economy/market" class="econ-back">Back to the open market</Link>
        </p>
    </PageScaffold>
</template>

<style scoped>
.req-hero {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: var(--space-3, 1rem);
    flex-wrap: wrap;
}
.req-kind {
    margin: 0;
    font-size: var(--text-sm, 0.875rem);
    color: var(--gov-fg-muted, #667);
}
.req-title {
    margin: 0;
    font-size: var(--text-lg, 1.25rem);
    color: var(--gov-fg, #223);
}
.req-org {
    margin: 0;
    color: var(--gov-fg-muted, #667);
}
.req-rate {
    text-align: end;
    display: flex;
    flex-direction: column;
}
.req-rate strong {
    font-size: var(--text-lg, 1.25rem);
    color: var(--gov-fg, #223);
}
.req-rate span {
    font-size: var(--text-sm, 0.875rem);
    color: var(--gov-fg-muted, #667);
}
.req-steps {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: var(--space-3, 1rem);
}
.req-step-head {
    display: flex;
    align-items: center;
    gap: var(--space-2, 0.5rem);
    flex-wrap: wrap;
}
.req-step-n {
    inline-size: 1.75rem;
    block-size: 1.75rem;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: var(--gov-surface-subtle, #eef);
    color: var(--gov-fg, #223);
    font-weight: 600;
    flex: none;
}
.req-step-actor {
    font-size: var(--text-sm, 0.875rem);
    color: var(--gov-fg-muted, #667);
}
.req-thresholds {
    margin: 0;
    padding-inline-start: 1.25rem;
    display: flex;
    flex-direction: column;
    gap: var(--space-2, 0.5rem);
}
</style>
