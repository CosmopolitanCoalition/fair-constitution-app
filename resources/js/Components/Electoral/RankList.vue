<script setup>
/**
 * Electoral/RankList — click-to-rank, keyboard operable, NO drag
 * (.rank-list / .rank-item / .rank-controls). PHASE_B_DESIGN_frontend.md
 * §A.3; mockup contract (ranked-ballot.html About note): "ranking is
 * click-to-rank with ↑/↓ — keyboard operable, no drag required".
 *
 * FE-C1 generalization (PHASE_C_DESIGN_frontend.md §A.5): the item shape
 * is `{ id, name, chips: [] }` — was the electoral-specific
 * `{ candidacy_id, name, write_in }` (RankedBallot maps candidacy_id → id,
 * write_in → chips: ['write-in']). `removable: false` serves the committee
 * preference ranker (F-LEG-010): every member ranks the FULL committee
 * list — no remove button, no empty-list path.
 *
 * A11y beyond the mockup (which loses focus on re-render — this port must
 * not):
 *  - after move: re-focus the SAME control on the moved item at its new
 *    index (falling back to the item's nearest enabled control when the
 *    same one is disabled at an edge); announce
 *    "{name} moved to rank {n} of {total}";
 *  - after remove: focus the next item's remove button (when the list
 *    empties, the parent's add controls are next in DOM order); announce
 *    "{name} removed — {total} ranked";
 *  - Alt+ArrowUp/Down anywhere inside a .rank-item moves it (same handler
 *    as the buttons).
 *
 * Nothing persists server-side before commit — the v-model is page-local
 * state only (ballot UX integrity §D).
 */
import { nextTick } from 'vue';
import Btn from '@/Components/Ui/Btn.vue';
import TagChip from '@/Components/Ui/TagChip.vue';
import { useAnnounce } from '@/composables/useAnnounce';

const props = defineProps({
    /** [{ id, name, chips: [] }] in rank order. */
    modelValue: { type: Array, required: true },
    /** Guidance copy denominator (the parent renders the gloss). */
    seats: { type: Number, required: true },
    /** Post-commit lock (aria-disabled on the <ol>). */
    disabled: { type: Boolean, default: false },
    /** false = committee-preference mode: rank everything, remove nothing. */
    removable: { type: Boolean, default: true },
});

const emit = defineEmits(['update:modelValue']);
const { announce } = useAnnounce();

/* Function-ref registry keyed by STABLE id (never by index — a removal
   makes the unmounting row's ref(null) fire after the shifted row's
   assignment, nulling the shared index slot and dropping focus).
   Btn is a single-root component, so the instance's $el IS the button. */
const controlEls = new Map(); // id → { up, down, remove }
function setControlRef(kind, entry) {
    return (instance) => {
        let rec = controlEls.get(entry.id);
        if (!rec) {
            rec = {};
            controlEls.set(entry.id, rec);
        }
        rec[kind] = instance?.$el ?? instance ?? null;
    };
}

function focusControl(kind, entry) {
    const rec = controlEls.get(entry?.id) ?? {};
    const order = {
        up: ['up', 'down', 'remove'],
        down: ['down', 'up', 'remove'],
        remove: ['remove', 'down', 'up'],
    }[kind];
    for (const candidate of order) {
        const el = rec[candidate];
        if (el && !el.disabled) {
            el.focus();
            return;
        }
    }
}

async function move(index, dir, kind) {
    if (props.disabled) return;
    const target = index + dir;
    if (target < 0 || target >= props.modelValue.length) return;
    const next = [...props.modelValue];
    const [item] = next.splice(index, 1);
    next.splice(target, 0, item);
    emit('update:modelValue', next);
    await nextTick();
    focusControl(kind, item);
    announce(`${item.name} moved to rank ${target + 1} of ${next.length}`);
}

async function remove(index) {
    if (props.disabled || !props.removable) return;
    const next = [...props.modelValue];
    const [item] = next.splice(index, 1);
    emit('update:modelValue', next);
    await nextTick();
    if (next.length > 0) {
        focusControl('remove', next[Math.min(index, next.length - 1)]);
    }
    announce(`${item.name} removed — ${next.length} ranked`);
}

/* Alt+ArrowUp/Down on a focused control inside the item (bubbles to the
   <li>); the moved item keeps focus on the control the keys came from. */
function onItemKeydown(event, index) {
    if (!event.altKey || (event.key !== 'ArrowUp' && event.key !== 'ArrowDown')) return;
    event.preventDefault();
    const kind = event.target.closest?.('[data-rank-control]')?.dataset.rankControl ?? 'up';
    move(index, event.key === 'ArrowUp' ? -1 : 1, kind);
}
</script>

<template>
    <ol
        class="rank-list"
        aria-label="Your ranked candidates"
        :aria-disabled="disabled ? 'true' : undefined"
    >
        <li
            v-for="(entry, index) in modelValue"
            :key="entry.id"
            class="rank-item"
            @keydown="onItemKeydown($event, index)"
        >
            <!-- The CSS ::before counter is not reliably announced. -->
            <span class="visually-hidden">Rank {{ index + 1 }}</span>
            <span style="flex: 1; color: var(--gov-fg)">
                {{ entry.name }}
                {{ ' ' }}
                <TagChip v-for="chip in entry.chips ?? []" :key="chip">{{ chip }}</TagChip>
            </span>
            <span class="rank-controls">
                <Btn
                    :ref="setControlRef('up', entry)"
                    variant="secondary"
                    icon="arrow-up"
                    data-rank-control="up"
                    :disabled="disabled || index === 0"
                    :aria-label="`Move ${entry.name} up`"
                    @click="move(index, -1, 'up')"
                />
                <Btn
                    :ref="setControlRef('down', entry)"
                    variant="secondary"
                    icon="arrow-down"
                    data-rank-control="down"
                    :disabled="disabled || index === modelValue.length - 1"
                    :aria-label="`Move ${entry.name} down`"
                    @click="move(index, 1, 'down')"
                />
                <Btn
                    v-if="removable"
                    :ref="setControlRef('remove', entry)"
                    variant="danger"
                    icon="x"
                    data-rank-control="remove"
                    :disabled="disabled"
                    :aria-label="`Remove ${entry.name}`"
                    @click="remove(index)"
                />
            </span>
        </li>
    </ol>
</template>
