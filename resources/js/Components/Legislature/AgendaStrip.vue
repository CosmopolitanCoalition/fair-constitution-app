<script setup>
/**
 * Legislature/AgendaStrip — the session agenda (FE-C1;
 * PHASE_C_DESIGN_frontend.md §A.4). Port of the session-console agenda
 * renderer (mockups/legislature/session-console.html lines 243–259):
 * constitutional order with positions 1–2 LOCKED (gold border + lock) —
 * emergency powers first, constitutional matters second, then the
 * Speaker-ordered general agenda (Art. II §2; §7).
 *
 * Slot 1 renders even when no emergency power is outstanding (status
 * 'none' — the locked slot existing-but-empty IS the constitutional
 * statement); slot 2 same for constitutional matters (fed by Phase E
 * challenges — honest empty state until then).
 *
 * Reorder = ↑/↓ Btns on unlocked items only (the RankList interaction
 * pattern: focus-retained, useAnnounce); the server re-guards via the
 * F-SPK-002 handler. Emit-only — the page owns the POST.
 */
import { computed, nextTick } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Btn from '@/Components/Ui/Btn.vue';
import HardenedChip from '@/Components/Ui/HardenedChip.vue';
import Icon from '@/Components/Ui/Icon.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import { useAnnounce } from '@/composables/useAnnounce';

const { t } = useI18n();

const props = defineProps({
    /**
     * [{ position, locked: bool, kind, title, subject: {type,id,href}|null,
     *    status: 'pending'|'in_progress'|'done'|'none' }]
     */
    items: { type: Array, required: true },
    /** R-10 + session open — renders the reorder controls. */
    editable: { type: Boolean, default: false },
});

const emit = defineEmits(['reorder']);
const { announce } = useAnnounce();

const kindLabel = (kind) =>
    ({
        emergency_powers: t('c_institution_components.agenda_strip.kind_emergency_powers', 'Outstanding emergency powers'),
        constitutional_matters: t('c_institution_components.agenda_strip.kind_constitutional_matters', 'Constitutional matters'),
        committee_report: t('c_institution_components.agenda_strip.kind_committee_report', 'Committee report'),
        priority: t('c_institution_components.agenda_strip.kind_priority', 'Member priority'),
        motion: t('c_institution_components.agenda_strip.kind_motion', 'Motions'),
        statement: t('c_institution_components.agenda_strip.kind_statement', 'Statements'),
        other: t('c_institution_components.agenda_strip.kind_other', 'General'),
    })[kind] ?? kind;

const STATUS_BADGES = {
    pending: { tone: 'info', icon: 'clock' },
    in_progress: { tone: 'warning', icon: 'clock' },
    done: { tone: 'success', icon: 'check' },
};
const statusText = (s) =>
    ({
        pending: t('c_institution_components.agenda_strip.status_pending', 'Pending'),
        in_progress: t('c_institution_components.agenda_strip.status_in_progress', 'In progress'),
        done: t('c_institution_components.agenda_strip.status_done', 'Done'),
    })[s];

const LOCK_TITLE = computed(() =>
    t('c_institution_components.agenda_strip.lock_title', 'Constitutional order — cannot be reordered or removed · Art. II §2; §7'),
);

/* Focus-retention registry, keyed by stable position-at-mount id (the
   RankList function-ref pattern — never index-keyed). */
const controlEls = new Map(); // item key → { up, down }
function itemKey(item) {
    return `${item.kind}:${item.title}`;
}
function setControlRef(kind, item) {
    return (instance) => {
        let rec = controlEls.get(itemKey(item));
        if (!rec) {
            rec = {};
            controlEls.set(itemKey(item), rec);
        }
        rec[kind] = instance?.$el ?? instance ?? null;
    };
}

function canMove(index, dir) {
    const target = index + dir;
    if (target < 0 || target >= props.items.length) return false;
    return !props.items[index].locked && !props.items[target].locked;
}

async function move(index, dir, kind) {
    if (!props.editable || !canMove(index, dir)) return;
    const item = props.items[index];
    emit('reorder', index, index + dir);
    await nextTick();
    const rec = controlEls.get(itemKey(item)) ?? {};
    const order = kind === 'up' ? ['up', 'down'] : ['down', 'up'];
    for (const candidate of order) {
        const el = rec[candidate];
        if (el && !el.disabled) {
            el.focus();
            break;
        }
    }
    announce(t('c_institution_components.agenda_strip.moved_announce', '{title} moved to position {pos} of {total}', { named: { title: item.title, pos: index + dir + 1, total: props.items.length } }));
}
</script>

<template>
    <ol class="agenda-list" :aria-label="t('c_institution_components.agenda_strip.list_aria', 'Session agenda — constitutional order')">
        <li
            v-for="(item, index) in items"
            :key="itemKey(item)"
            class="agenda-slot"
            :class="{ 'agenda-slot--locked': item.locked }"
        >
            <span class="flow-step-n">{{ item.position }}</span>

            <div style="flex: 1 1 auto; min-inline-size: 0">
                <span class="eyebrow">
                    {{ kindLabel(item.kind) }}
                    <template v-if="item.locked">
                        <Icon name="lock" size="sm" :label="LOCK_TITLE" />
                    </template>
                </span>
                <div style="margin-block-start: var(--space-1)">
                    <strong v-if="item.status !== 'none'" style="color: var(--gov-fg)">{{ item.title }}</strong>
                    <span v-else>{{ item.title }}</span>
                    <template v-if="item.subject">
                        {{ ' ' }}
                        <Link :href="item.subject.href">{{ t('c_institution_components.agenda_strip.open', 'Open') }}</Link>
                    </template>
                </div>
                <div v-if="item.locked" style="margin-block-start: var(--space-1)">
                    <HardenedChip :title="LOCK_TITLE">{{ LOCK_TITLE }}</HardenedChip>
                </div>
            </div>

            <span class="cluster" style="flex-wrap: nowrap; gap: var(--space-1)">
                <StatusBadge
                    v-if="STATUS_BADGES[item.status]"
                    :tone="STATUS_BADGES[item.status].tone"
                    :icon="STATUS_BADGES[item.status].icon"
                >{{ statusText(item.status) }}</StatusBadge>

                <template v-if="editable && !item.locked">
                    <Btn
                        :ref="setControlRef('up', item)"
                        variant="secondary"
                        size="sm"
                        icon="arrow-up"
                        :disabled="!canMove(index, -1)"
                        :aria-label="t('c_institution_components.agenda_strip.move_up_aria', 'Move {title} up', { named: { title: item.title } })"
                        @click="move(index, -1, 'up')"
                    />
                    <Btn
                        :ref="setControlRef('down', item)"
                        variant="secondary"
                        size="sm"
                        icon="arrow-down"
                        :disabled="!canMove(index, 1)"
                        :aria-label="t('c_institution_components.agenda_strip.move_down_aria', 'Move {title} down', { named: { title: item.title } })"
                        @click="move(index, 1, 'down')"
                    />
                </template>
            </span>
        </li>
    </ol>
</template>
