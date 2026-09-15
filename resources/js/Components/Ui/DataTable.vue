<script setup>
/**
 * Ui/DataTable — .table inside the .table-wrap scroll container. The wrapper
 * is structural (WCAG 1.4.10 reflow fix), so it always renders.
 *
 * THE WRAPPER ONLY WORKS IF THE TABLE IS ALLOWED TO BE WIDER THAN IT.
 * `.table` carries `inline-size: 100%`, so on a narrow screen the table
 * shrank to fit instead of overflowing — `overflow-x: auto` never engaged and
 * every cell wrapped instead. A seven-column ledger became roughly ten phone
 * screens of crushed text: technically reflowed, practically unreadable.
 * Measured on /economy/treasury at 375px — 8,667 CSS px tall, 2.4x its own
 * desktop height, where every other page in that set sat between 1.5x and
 * 1.9x. The min-width below lets the table exceed its container so the
 * scroller does its job, and the columns keep a legible width.
 *
 * columns: [{ key, label, mono?, align? }]
 * Cell content overridable per column via scoped slots: #cell-{key}="{ row, value }".
 */
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    columns: { type: Array, required: true },
    rows: { type: Array, required: true },
    /** Row property used as :key; falls back to the row index. */
    rowKey: { type: String, default: null },
    /** Visually-hidden table caption for screen readers. */
    caption: { type: String, default: null },
});

// A scroll container that overflows is a scrollable region. WCAG 2.1.1 wants
// it reachable by keyboard, so give it tabindex 0 and an accessible name WHEN
// it overflows, and neither when it does not. Measured with a ResizeObserver
// so the state follows the viewport, not just the first paint.
const wrap = ref(null);
const overflowing = ref(false);

function measure() {
    const el = wrap.value;
    if (!el) return;
    overflowing.value = el.scrollWidth > el.clientWidth + 1;
}

let ro = null;
onMounted(() => {
    measure();
    if (typeof ResizeObserver !== 'undefined' && wrap.value) {
        ro = new ResizeObserver(() => measure());
        ro.observe(wrap.value);
    }
    if (typeof window !== 'undefined') window.addEventListener('resize', measure);
});
onBeforeUnmount(() => {
    if (ro) ro.disconnect();
    if (typeof window !== 'undefined') window.removeEventListener('resize', measure);
});
</script>

<template>
    <div
        ref="wrap"
        class="table-wrap"
        :tabindex="overflowing ? 0 : undefined"
        :role="overflowing ? 'region' : undefined"
        :aria-label="overflowing ? (caption ? t('c_ui_a.data_table.caption_scrollable', '{caption} (scrollable)', { named: { caption } }) : t('c_ui_a.data_table.scrollable_table', 'Scrollable table')) : undefined"
    >
        <table class="table">
            <caption v-if="caption" class="visually-hidden">{{ caption }}</caption>
            <thead>
                <tr>
                    <th
                        v-for="col in columns"
                        :key="col.key"
                        scope="col"
                        :class="{ mono: col.mono }"
                        :style="col.align ? { textAlign: col.align } : undefined"
                    >{{ col.label }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(row, ri) in rows" :key="rowKey ? row[rowKey] : ri">
                    <td
                        v-for="col in columns"
                        :key="col.key"
                        :class="{ mono: col.mono }"
                        :style="col.align ? { textAlign: col.align } : undefined"
                    >
                        <slot :name="`cell-${col.key}`" :row="row" :value="row[col.key]">
                            {{ row[col.key] }}
                        </slot>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>

<style scoped>
/* Below this width a multi-column table cannot stay legible by shrinking, so
   let it overflow and let .table-wrap scroll it. Above it, nothing changes:
   the table still fills its container exactly as before. */
@media (max-width: 40rem) {
    .table-wrap :deep(.table) {
        min-inline-size: 34rem;
    }
}
</style>
