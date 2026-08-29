<script setup>
/**
 * B4 — the floating background-job monitor (operator ruling 2026-08-29).
 *
 * Polls /api/background-jobs and renders one honest bar per long-running
 * workstream (autoscale sizing/singles/sweeps, geodata ingestion, map:sweep
 * piles, subtree activations). Renders nothing at all when the box is quiet.
 * A stream with no known total shows its done-count only — the visibility
 * law forbids fabricated progress, so there is no indeterminate shimmer
 * pretending to be measurement.
 */
import { onBeforeUnmount, onMounted, ref } from 'vue';

const streams = ref([]);
const collapsed = ref(false);
let timer = null;
let failures = 0;

try {
    collapsed.value = localStorage.getItem('bg-jobs-collapsed') === '1';
} catch { /* storage unavailable — default stays */ }

function toggle() {
    collapsed.value = !collapsed.value;
    try {
        localStorage.setItem('bg-jobs-collapsed', collapsed.value ? '1' : '0');
    } catch { /* ignore */ }
}

async function poll() {
    try {
        const res = await fetch('/api/background-jobs', { headers: { Accept: 'application/json' } });
        if (!res.ok) throw new Error(String(res.status));
        const body = await res.json();
        streams.value = Array.isArray(body.streams) ? body.streams : [];
        failures = 0;
    } catch {
        // Quiet backoff: after three straight failures stop claiming
        // anything — an empty widget is honest, a stale one is not.
        if (++failures >= 3) streams.value = [];
    }
    timer = setTimeout(poll, failures > 0 ? 30000 : 8000);
}

function pct(s) {
    if (!s.total || s.total <= 0) return null;
    return Math.min(100, Math.round((s.done / s.total) * 100));
}

onMounted(poll);
onBeforeUnmount(() => { if (timer) clearTimeout(timer); });
</script>

<template>
    <div v-if="streams.length" class="bgjobs" role="status" aria-live="polite">
        <button class="bgjobs-head" type="button" @click="toggle"
                :aria-expanded="String(!collapsed)"
                :title="collapsed ? 'Show background jobs' : 'Hide background jobs'">
            <span class="bgjobs-dot" aria-hidden="true"></span>
            <span>{{ streams.length }} background {{ streams.length === 1 ? 'job' : 'jobs' }}</span>
            <span class="bgjobs-chev" aria-hidden="true">{{ collapsed ? '▸' : '▾' }}</span>
        </button>
        <ul v-if="!collapsed" class="bgjobs-list">
            <li v-for="s in streams" :key="s.key" class="bgjobs-item">
                <div class="bgjobs-label">
                    <span>{{ s.label }}</span>
                    <span class="bgjobs-nums">
                        {{ s.done.toLocaleString() }}<template v-if="s.total"> / {{ s.total.toLocaleString() }}</template>
                    </span>
                </div>
                <div v-if="pct(s) !== null" class="bgjobs-bar">
                    <div class="bgjobs-fill" :style="{ width: pct(s) + '%' }"></div>
                </div>
            </li>
        </ul>
    </div>
</template>

<style scoped>
.bgjobs {
    position: fixed;
    right: 1rem;
    bottom: 3.25rem;
    z-index: 40;
    width: 19rem;
    max-width: calc(100vw - 2rem);
    background: rgba(13, 22, 34, 0.94);
    border: 1px solid rgba(94, 234, 212, 0.25);
    border-radius: 0.5rem;
    color: #d1dce8;
    font-size: 0.75rem;
    backdrop-filter: blur(4px);
}
.bgjobs-head {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    width: 100%;
    padding: 0.45rem 0.65rem;
    background: none;
    border: 0;
    color: inherit;
    font: inherit;
    cursor: pointer;
    text-align: left;
}
.bgjobs-dot {
    width: 0.5rem;
    height: 0.5rem;
    border-radius: 9999px;
    background: #2dd4bf;
    animation: bgjobs-pulse 2s ease-in-out infinite;
}
@keyframes bgjobs-pulse {
    50% { opacity: 0.35; }
}
.bgjobs-chev { margin-left: auto; opacity: 0.7; }
.bgjobs-list {
    list-style: none;
    margin: 0;
    padding: 0 0.65rem 0.55rem;
    display: flex;
    flex-direction: column;
    gap: 0.45rem;
    max-height: 40vh;
    overflow-y: auto;
}
.bgjobs-label {
    display: flex;
    justify-content: space-between;
    gap: 0.75rem;
    margin-bottom: 0.15rem;
}
.bgjobs-nums { opacity: 0.75; white-space: nowrap; }
.bgjobs-bar {
    height: 0.3rem;
    border-radius: 9999px;
    background: rgba(148, 163, 184, 0.25);
    overflow: hidden;
}
.bgjobs-fill {
    height: 100%;
    border-radius: 9999px;
    background: #2dd4bf;
    transition: width 0.6s ease;
}
</style>
