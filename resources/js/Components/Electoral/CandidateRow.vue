<script setup>
/**
 * Electoral/CandidateRow — one standings row on the open ballot
 * (.candidate-row family). PHASE_B_DESIGN_frontend.md §A.2; markup derived
 * from rowHtml() in mockups/electoral/open-ballot.html (lines 167–190).
 *
 * Deliberate delta from the mockup: the mockup adds the viewer's own
 * approval (+1) to the displayed aggregate. Production must NOT — a
 * single-voter live delta on a daily aggregate de-anonymizes the approval
 * (ballot secrecy · Art. II §2). The switch flips; the aggregate holds.
 *
 * `rank` is ALWAYS the full-race rank, never a filtered rank.
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import ApproveSwitch from '@/Components/Electoral/ApproveSwitch.vue';
import OrgChip from '@/Components/Ui/OrgChip.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import TagChip from '@/Components/Ui/TagChip.vue';

const props = defineProps({
    /**
     * { id, name, statement, position_tags: [], incumbent: bool,
     *   profile_href, endorsements: { orgs: [{id,name,type}],
     *   individual_count: int } }
     */
    candidacy: { type: Object, required: true },
    /** Full-race rank (the FinalistLine positions by this). */
    rank: { type: Number, required: true },
    /** Aggregate, daily (approval_standings.approvals_count). */
    approvals: { type: Number, required: true },
    /** approval_standings.delta (signed). */
    delta: { type: Number, default: 0 },
    /** Viewer's own approval (owner-only read of the approvals table). */
    approved: { type: Boolean, default: false },
    /** phase === 'approval' && viewer R-04 in the race. */
    approvable: { type: Boolean, default: false },
    busy: { type: Boolean, default: false },
    /**
     * Omission knob (not in the §A.2 prop list, which the grid spec still
     * requires): false = the viewer lacks association in the race
     * jurisdiction → the switch is OMITTED entirely, not just disabled
     * (browsing another county's race). `approvable=false` with the switch
     * shown = phase closed → disabled with the title reason.
     */
    showSwitch: { type: Boolean, default: true },
});

const emit = defineEmits(['toggle-approve']);

const orgs = computed(() => props.candidacy.endorsements?.orgs ?? []);
const individualCount = computed(() => props.candidacy.endorsements?.individual_count ?? 0);
const noEndorsements = computed(() => orgs.value.length === 0 && individualCount.value === 0);
const tags = computed(() => props.candidacy.position_tags ?? []);

const linkTitle = computed(
    () =>
        `${props.candidacy.name} — open public profile · rank ${props.rank} · ` +
        `${props.approvals.toLocaleString()} approvals`,
);
</script>

<template>
    <div class="candidate-row" :data-rank="rank">
        <ApproveSwitch
            v-if="showSwitch"
            :pressed="approved"
            :candidate-name="candidacy.name"
            :disabled="!approvable"
            :busy="busy"
            @update:pressed="(next) => emit('toggle-approve', candidacy.id, next)"
        />
        <!-- keep .candidate-main in grid column 2 when the switch is omitted -->
        <span v-else aria-hidden="true"></span>

        <div class="candidate-main">
            <span class="citation" style="margin-inline-end: var(--space-2)">#{{ rank }}</span>
            <Link class="candidate-name" :href="candidacy.profile_href" :title="linkTitle">{{
                candidacy.name
            }}</Link>
            {{ ' ' }}
            <StatusBadge v-if="candidacy.incumbent" tone="neutral">incumbent</StatusBadge>
            <span
                v-if="candidacy.statement"
                class="cc-small"
                style="display: block; color: var(--gov-fg-muted)"
            >{{ candidacy.statement }}</span>
            <span class="candidate-meta">
                <OrgChip
                    v-for="org in orgs"
                    :key="org.id"
                    :name="org.name"
                    :org-type="org.type"
                    style="padding-block: 0; font-size: var(--text-xs)"
                />
                <TagChip v-if="individualCount">{{ individualCount }} individual endorsements</TagChip>
                <!-- zero-endorsement candidates are first-class -->
                <TagChip v-if="noEndorsements">no endorsements</TagChip>
                <TagChip v-for="tag in tags" :key="tag">{{ tag }}</TagChip>
                <slot name="meta" />
            </span>
        </div>

        <div class="standing">
            <span class="standing-approvals">{{ approvals.toLocaleString() }}</span>
            <span v-if="delta > 0" class="standing-delta standing-delta--up">▲ {{ delta }} since yesterday</span>
            <span v-else-if="delta < 0" class="standing-delta standing-delta--down">▼ {{ Math.abs(delta) }} since yesterday</span>
            <span v-else class="standing-delta standing-delta--flat">— steady<span class="visually-hidden"> since yesterday</span></span>
        </div>
    </div>
</template>
