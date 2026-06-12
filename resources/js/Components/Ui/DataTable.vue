<script setup>
/**
 * Ui/DataTable — .table inside the .table-wrap scroll container. The wrapper
 * is structural (WCAG 1.4.10 reflow fix), so it always renders.
 *
 * columns: [{ key, label, mono?, align? }]
 * Cell content overridable per column via scoped slots: #cell-{key}="{ row, value }".
 */
defineProps({
    columns: { type: Array, required: true },
    rows: { type: Array, required: true },
    /** Row property used as :key; falls back to the row index. */
    rowKey: { type: String, default: null },
    /** Visually-hidden table caption for screen readers. */
    caption: { type: String, default: null },
});
</script>

<template>
    <div class="table-wrap">
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
