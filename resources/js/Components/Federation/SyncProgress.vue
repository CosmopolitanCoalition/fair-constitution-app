<script setup>
// G3b — live seed/drain progress for a mirror join, the federation counterpart to
// the setup wizard's data-import progress (StackedProgressBars). Polls the
// public-read sync-progress endpoint and renders one bar per phase:
//   • Seed download — a real %/ETA (the manifest declares the total bytes);
//   • Import — opaque (tar + pg_restore), an honest indeterminate "working" bar;
//   • Audit history — a live record count (the cold cursor has no a-priori target).
// ETA/elapsed math + the 2 s poll cadence mirror the setup component exactly.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    pollUrl: { type: String, default: '/federation/cluster/sync-progress' },
});

// `done` fires ONCE when the drain catches up (membership LIVE) — the setup wizard listens to
// finalize the join. `lifecycle` fires every poll with the current lifecycle string (running / done /
// failed / idle) so the parent can shape its controls (e.g. show "Syncing…" vs "Resume").
const emit = defineEmits(['done', 'lifecycle']);

const progress = ref(null);
let timer = null;
let doneEmitted = false;

const lifecycle = computed(() => progress.value?.lifecycle ?? 'idle');
const visible = computed(() => !!progress.value && lifecycle.value !== 'idle');
const bars = computed(() => progress.value?.bars ?? []);

async function fetchProgress() {
    try {
        const res = await fetch(props.pollUrl, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        if (!res.ok) return;
        progress.value = await res.json();
    } catch (e) {
        return; // swallow — the next tick retries
    }
    emit('lifecycle', lifecycle.value);
    if (lifecycle.value === 'done' && !doneEmitted) {
        doneEmitted = true;
        emit('done');
    }
    lifecycle.value === 'running' ? arm() : disarm();
}

function arm() {
    if (!timer) timer = setInterval(fetchProgress, 2000);
}
function disarm() {
    if (timer) {
        clearInterval(timer);
        timer = null;
    }
}

// Called by the parent right after a join POST so polling begins immediately.
function start() {
    fetchProgress();
}

onMounted(fetchProgress);
onBeforeUnmount(disarm);
defineExpose({ start });

// ── formatting — mirrors the setup wizard's StackedProgressBars ──────────────
function fmtBytes(n) {
    if (n == null || !Number.isFinite(n)) return '—';
    if (n < 1024) return `${n} B`;
    const u = ['KB', 'MB', 'GB', 'TB'];
    let v = n / 1024;
    let i = 0;
    while (v >= 1024 && i < u.length - 1) {
        v /= 1024;
        i++;
    }
    return `${v.toFixed(v < 10 ? 1 : 0)} ${u[i]}`;
}
function fmtNum(n) {
    return n == null ? '—' : Number(n).toLocaleString();
}
function fmtDuration(seconds) {
    if (seconds == null || seconds < 0 || !Number.isFinite(seconds)) return '—';
    const s = Math.round(seconds);
    if (s < 60) return `${s}s`;
    if (s < 3600) {
        const m = Math.floor(s / 60);
        const r = s % 60;
        return r ? `${m}m ${r}s` : `${m}m`;
    }
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    return m ? `${h}h ${m}m` : `${h}h`;
}
function elapsed(bar) {
    if (!bar?.started_at) return null;
    const start = Date.parse(bar.started_at);
    if (Number.isNaN(start)) return null;
    const end = bar.completed_at ? Date.parse(bar.completed_at) : Date.now();
    return (end - start) / 1000;
}
function pct(bar) {
    if (bar.total == null || bar.total <= 0) return null;
    return Math.min(100, Math.round((bar.current / bar.total) * 100));
}
function eta(bar) {
    if (bar.status === 'done' || bar.total == null || !bar.current) return null;
    const e = elapsed(bar);
    if (!e || e < 1) return null;
    const rate = bar.current / e;
    if (rate <= 0) return null;
    return (bar.total - bar.current) / rate;
}
function ratePerSec(bar) {
    const e = elapsed(bar);
    if (!e || e < 1 || !bar.current) return null;
    return bar.current / e;
}
const fmtValue = (bar, n) => (bar.unit === 'bytes' ? fmtBytes(n) : fmtNum(n));

const barClass = (bar) =>
    ({ done: 'bg-emerald-500', running: 'bg-sky-500', failed: 'bg-rose-500', pending: 'bg-slate-300' }[bar.status] || 'bg-slate-300');
const lifecycleBadge = computed(
    () =>
        ({ running: 'bg-sky-100 text-sky-800', done: 'bg-emerald-100 text-emerald-800', failed: 'bg-rose-100 text-rose-700' }[
            lifecycle.value
        ] || 'bg-slate-100 text-slate-600'),
);
const lifecycleLabel = computed(
    () => ({ running: 'Syncing…', done: 'Caught up', failed: 'Stalled', idle: 'Idle' }[lifecycle.value] || lifecycle.value),
);
</script>

<template>
    <div v-if="visible" class="rounded border border-sky-200 bg-sky-50/60 p-4">
        <div class="flex items-center justify-between">
            <h3 class="text-xs font-semibold uppercase tracking-wide text-sky-800">Joining the cluster</h3>
            <span class="rounded px-2 py-0.5 text-xs font-medium" :class="lifecycleBadge">{{ lifecycleLabel }}</span>
        </div>

        <p v-if="lifecycle === 'failed' && progress.error"
           class="mt-2 rounded border border-rose-200 bg-rose-50 px-2 py-1 text-xs text-rose-700">
            {{ progress.error }}
        </p>

        <ul class="mt-3 space-y-3">
            <li v-for="bar in bars" :key="bar.key">
                <div class="flex items-baseline justify-between text-sm">
                    <span class="font-medium text-slate-700">{{ bar.label }}</span>
                    <span class="text-xs text-slate-500">
                        <template v-if="bar.status === 'done'">done<span v-if="elapsed(bar)"> · {{ fmtDuration(elapsed(bar)) }}</span></template>
                        <template v-else-if="bar.status === 'failed'">failed</template>
                        <template v-else-if="bar.status === 'pending'">waiting…</template>
                        <template v-else-if="pct(bar) !== null">{{ pct(bar) }}%</template>
                        <template v-else>working…</template>
                    </span>
                </div>

                <!-- Determinate (seed download): true %/ETA -->
                <div v-if="bar.total != null && !bar.indeterminate" class="mt-1">
                    <div class="h-2 w-full overflow-hidden rounded bg-slate-200">
                        <div class="h-full rounded transition-all" :class="barClass(bar)" :style="{ width: (pct(bar) ?? 0) + '%' }"></div>
                    </div>
                    <div class="mt-1 flex justify-between text-xs text-slate-500">
                        <span>{{ fmtValue(bar, bar.current) }} of {{ fmtValue(bar, bar.total) }}</span>
                        <span v-if="bar.status === 'running' && eta(bar) != null">~{{ fmtDuration(eta(bar)) }} left</span>
                    </div>
                </div>

                <!-- Indeterminate (import / audit drain): activity stripe + live count -->
                <div v-else class="mt-1">
                    <div class="h-2 w-full overflow-hidden rounded bg-slate-200">
                        <div class="h-full rounded"
                             :class="[barClass(bar), bar.status === 'running' ? 'sync-stripes w-full' : (bar.status === 'done' ? 'w-full' : 'w-0')]"></div>
                    </div>
                    <div class="mt-1 flex justify-between text-xs text-slate-500">
                        <span v-if="bar.unit === 'records'">
                            {{ fmtNum(bar.current) }} records<span v-if="bar.pages"> · {{ fmtNum(bar.pages) }} pages</span>
                        </span>
                        <span v-else-if="bar.status === 'running'">Importing the foundation into the database…</span>
                        <span v-else-if="bar.status === 'done'">Imported</span>
                        <span v-else>waiting…</span>
                        <span v-if="bar.status === 'running' && bar.unit === 'records' && ratePerSec(bar)">{{ fmtNum(Math.round(ratePerSec(bar))) }}/s</span>
                    </div>
                </div>
            </li>
        </ul>

        <p class="mt-3 text-xs text-slate-500">
            You can leave this page — the sync runs in the background and resumes if interrupted.
        </p>
    </div>
</template>

<style scoped>
.sync-stripes {
    background-image: linear-gradient(
        45deg,
        rgba(255, 255, 255, 0.35) 25%,
        transparent 25%,
        transparent 50%,
        rgba(255, 255, 255, 0.35) 50%,
        rgba(255, 255, 255, 0.35) 75%,
        transparent 75%,
        transparent
    );
    background-size: 1rem 1rem;
    animation: sync-stripes-move 1s linear infinite;
}
@keyframes sync-stripes-move {
    from {
        background-position: 0 0;
    }
    to {
        background-position: 1rem 0;
    }
}
</style>
