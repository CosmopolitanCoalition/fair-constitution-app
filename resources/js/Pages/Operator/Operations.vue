<script setup>import { useLocaleFormat } from '@/composables/useLocaleFormat';
const localeFmt = useLocaleFormat();
// Operator Operations console (Phase 1, read-only) — the infrastructure & identity
// inventory. Every hardcoded / env-baked / file-managed knob in one place, with its
// apply tier and live status. Secrets are surfaced as configured?/dev-default? only —
// their values never reach this page. Edit/apply controls are a later increment
// (Tier-A instant edits, then the Tier-B host-apply supervisor + credential pass).
import { computed, reactive, ref, onMounted, onBeforeUnmount } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShell from '@/Layouts/AppShell.vue';
import HostNav from '@/Components/Operator/HostNav.vue';

/* KEEP-class (V3 synthesis S1): pinned to the v1 shell when AppShellV2 became
   the app-wide default — legacy operations console; zero change intended. */
defineOptions({ layout: AppShell });

const { t } = useI18n();

/* The instance class act (operator ruling 2026-09-17): a founded box flips to
   scale_demo (arms demo mode) or back, recorded on the audit log. */
const classDraft = ref(null);
const classReason = ref('');
const classBusy = ref(false);
function submitInstanceClass() {
    const target = classDraft.value ?? props.instanceClass;
    if (classBusy.value || !target || target === props.instanceClass) return;
    router.post('/operator/operations/instance-class', { class: target, reason: classReason.value.trim() || null }, {
        preserveScroll: true,
        onStart: () => { classBusy.value = true; },
        onFinish: () => { classBusy.value = false; classDraft.value = null; classReason.value = ''; },
    });
}

const props = defineProps({
    instanceClass: { type: String, default: 'production' },
    authed: { type: Boolean, default: false },
    operator: { type: String, default: null },
    inventory: { type: Object, default: null },
});

const sections = computed(() => props.inventory?.sections ?? []);
const tiers = computed(() => props.inventory?.tiers ?? []);

const page = usePage();
const flash = computed(() => page.props.flash?.status ?? null);
const tuningError = computed(() => page.props.errors?.operator_tuning ?? null);

// Phase 2 — instant-tier edits. Drafts hold the in-progress input value per knob.
const drafts = reactive({});
const draftVal = (it) => (drafts[it.key] !== undefined ? drafts[it.key] : (it.value ?? ''));
const saveTuning = (it) =>
    router.post('/operator/operations/tuning', { key: it.key, value: draftVal(it) }, { preserveScroll: true });
const resetTuning = (it) =>
    router.post('/operator/operations/tuning/reset', { key: it.key }, { preserveScroll: true, onSuccess: () => delete drafts[it.key] });
const toggleBool = (it) =>
    router.post('/operator/operations/tuning', { key: it.key, value: it.value !== 'yes' }, { preserveScroll: true });

// Phase 3 — restart-tier host-apply (LiveKit ICE networking). Stage a change → the
// host supervisor rewrites .env + recreates the container → poll the lifecycle.
const applyError = computed(() => page.props.errors?.operator_apply ?? null);
const APPLYABLE = [
    { key: 'LIVEKIT_NODE_IP', label: t('c_operator_pages.operations.applyable_node_ip', 'LiveKit ICE node IP'), from: 'livekit_node_ip' },
    { key: 'LIVEKIT_PUBLIC_URL', label: t('c_operator_pages.operations.applyable_public_url', 'LiveKit public (browser) URL'), from: 'livekit_public_url' },
];
const applyDrafts = reactive({});
const applyState = ref(null);
let applyTimer = null;
const liveValue = (fromKey) => {
    for (const s of sections.value) {
        const it = (s.items || []).find((i) => i.key === fromKey);
        if (it) return it.value ?? '';
    }
    return '';
};
const applyVal = (a) => (applyDrafts[a.key] !== undefined ? applyDrafts[a.key] : liveValue(a.from));
async function fetchApplyStatus() {
    try {
        const res = await fetch('/operator/operations/apply-status', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        if (res.ok) applyState.value = await res.json();
    } catch (e) {
        /* swallow — next tick retries */
    }
}
function submitApply() {
    const changes = {};
    for (const a of APPLYABLE) {
        const v = applyVal(a);
        if (v !== '' && v !== liveValue(a.from)) changes[a.key] = v;
    }
    if (Object.keys(changes).length === 0) return;
    router.post('/operator/operations/apply', { changes }, { preserveScroll: true, onSuccess: fetchApplyStatus });
}
const applyLabel = computed(
    () => ({
        pending: t('c_operator_pages.operations.apply_pending', 'Pending'),
        applying: t('c_operator_pages.operations.apply_applying', 'Applying…'),
        applied: t('c_operator_pages.operations.apply_applied', 'Applied ✓'),
        failed: t('c_operator_pages.operations.apply_failed', 'Failed'),
    }[applyState.value?.lifecycle] || ''),
);
onMounted(() => {
    if (props.authed) {
        fetchApplyStatus();
        applyTimer = setInterval(fetchApplyStatus, 3000);
    }
});
onBeforeUnmount(() => {
    if (applyTimer) clearInterval(applyTimer);
});

const tierBadge = (tier) =>
    ({
        instant: 'bg-emerald-100 text-emerald-800',
        restart: 'bg-amber-100 text-amber-800',
        locked: 'bg-slate-200 text-slate-700',
    }[tier] || 'bg-slate-100 text-slate-600');

const stateBadge = (state) =>
    ({
        established: 'bg-emerald-100 text-emerald-800',
        requested: 'bg-amber-100 text-amber-800',
        qualifiable: 'bg-sky-100 text-sky-800',
        lapsed: 'bg-rose-100 text-rose-700',
        needs_config: 'bg-slate-100 text-slate-600',
    }[state] || 'bg-slate-100 text-slate-600');

const certClass = (cert) =>
    cert.expired ? 'text-rose-700 font-semibold' : cert.expiring ? 'text-amber-700 font-medium' : 'text-slate-700';
const fmtDate = (iso) => (iso ? localeFmt.date(new Date(iso)) : '—');
</script>

<template>
    <Head :title="t('c_operator_pages.operations.head_title', 'Host resources and services')" />

    <div class="mx-auto max-w-5xl space-y-6 p-6">
        <header>
            <h1 class="text-lg font-semibold" style="color: var(--gov-fg-strong)">{{ t('c_operator_pages.operations.h1', 'Host resources and services') }}</h1>
            <p class="mt-1 max-w-3xl text-sm" style="color: var(--gov-fg-muted)">
                {{ t('c_operator_pages.operations.subtitle', 'Review this host’s infrastructure and edit supported settings. Each setting shows whether it applies immediately, requires a service restart, or is managed elsewhere.') }}
            </p>
        </header>
        <HostNav current="operations" />

        <p v-if="flash" class="rounded border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{{ flash }}</p>
        <p v-if="tuningError" class="rounded border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ tuningError }}</p>
        <p v-if="applyError" class="rounded border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ applyError }}</p>

        <!-- Not an operator → sign-in gate, no infra data -->
        <section v-if="!authed" class="rounded-lg border border-slate-200 bg-white p-6">
            <h2 class="text-sm font-semibold text-slate-900">{{ t('c_operator_pages.operations.signin_title', 'Operator sign-in required') }}</h2>
            <p class="mt-2 text-sm text-slate-600">
                {{ t('c_operator_pages.operations.signin_body', 'The operations inventory is shown only to a signed-in operator.') }}
            </p>
            <a href="/operator/login"
               class="mt-3 inline-block rounded bg-sky-700 px-3 py-1.5 text-sm font-medium text-white hover:bg-sky-800">
                {{ t('c_operator_pages.operations.signin_link', 'Sign in as an operator →') }}
            </a>
        </section>

        <template v-else>
            <!-- Tier legend -->
            <!-- The instance class act (operator ruling 2026-09-17) -->
            <section class="rounded-lg border border-slate-200 bg-white p-5" aria-labelledby="instance-class-h">
                <h2 id="instance-class-h" class="text-sm font-semibold text-slate-900">{{ t('c_operator_pages.operations.instance_class_h', 'Instance class') }}</h2>
                <p class="mt-1 text-sm text-slate-600">
                    {{ t('c_operator_pages.operations.instance_class_body', 'production runs a real world. scale_demo arms demo mode: guests go through the motions, their writes are session-scoped and purged, nothing enters the permanent record. Changing the class is an operator act recorded on the audit log.') }}
                </p>
                <p class="mt-2 text-sm text-slate-700">
                    {{ t('c_operator_pages.operations.instance_class_current', 'Current class') }}: <code>{{ instanceClass }}</code>
                </p>
                <form class="mt-3 flex flex-wrap items-end gap-3" @submit.prevent="submitInstanceClass">
                    <label class="block text-sm text-slate-700">
                        <span class="block text-xs font-medium text-slate-500">{{ t('c_operator_pages.operations.instance_class_target', 'New class') }}</span>
                        <select :value="classDraft ?? instanceClass" data-no-i18n class="mt-1 rounded border border-slate-300 px-2 py-1.5 text-sm" @change="classDraft = $event.target.value">
                            <option value="production">production</option>
                            <option value="scale_demo">scale_demo</option>
                        </select>
                    </label>
                    <label class="block text-sm text-slate-700">
                        <span class="block text-xs font-medium text-slate-500">{{ t('c_operator_pages.operations.instance_class_reason', 'Reason (recorded)') }}</span>
                        <input v-model="classReason" type="text" maxlength="200" class="mt-1 w-64 rounded border border-slate-300 px-2 py-1.5 text-sm" />
                    </label>
                    <button type="submit" :disabled="classBusy || (classDraft ?? instanceClass) === instanceClass"
                            class="rounded bg-sky-700 px-3 py-1.5 text-sm font-medium text-white disabled:bg-slate-300">
                        {{ classBusy ? t('c_operator_pages.operations.instance_class_saving', 'Recording…') : t('c_operator_pages.operations.instance_class_submit', 'Change class') }}
                    </button>
                </form>
            </section>
            <section class="rounded-lg border border-slate-200 bg-white p-4">
                <h2 class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('c_operator_pages.operations.apply_tiers', 'Apply tiers') }}</h2>
                <ul class="mt-2 grid gap-2 sm:grid-cols-3">
                    <li v-for="tier in tiers" :key="tier.key" class="text-xs text-slate-600">
                        <span class="rounded px-2 py-0.5 font-medium" :class="tierBadge(tier.key)">{{ tier.label }}</span>
                        <span class="ml-1">{{ tier.note }}</span>
                    </li>
                </ul>
                <p class="mt-3 text-xs text-slate-500">
                    {{ t('c_operator_pages.operations.signed_in_as', 'Signed in as') }} <span class="font-medium text-slate-700">{{ operator }}</span>{{ t('c_operator_pages.operations.secrets_note', '. Secrets show only whether they are set — values never leave the box.') }}
                </p>
            </section>

            <!-- One card per infra domain -->
            <section v-for="s in sections" :key="s.key" class="rounded-lg border border-slate-200 bg-white p-5">
                <div class="flex items-baseline justify-between gap-4">
                    <div>
                        <h2 class="text-sm font-semibold text-slate-900">{{ s.label }}</h2>
                        <p class="mt-0.5 max-w-2xl text-xs text-slate-500">{{ s.summary }}</p>
                    </div>
                    <a v-if="s.doc" :href="`https://github.com/CosmopolitanCoalition/fair-constitution-app/blob/main/${s.doc}`"
                       target="_blank" rel="noopener"
                       class="shrink-0 text-xs font-medium text-sky-700 hover:underline">{{ t('c_operator_pages.operations.runbook', 'Runbook ↗') }}</a>
                </div>

                <!-- Knobs -->
                <div class="mt-3 divide-y divide-slate-100">
                    <div v-for="it in s.items" :key="it.key" class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2">
                        <span class="w-48 shrink-0 text-sm text-slate-700">{{ it.label }}</span>
                        <span class="rounded px-1.5 py-0.5 text-[10px] font-medium uppercase" :class="tierBadge(it.tier)">{{ it.tier }}</span>

                        <span v-if="it.secret" class="text-sm">
                            <span v-if="!it.configured" class="text-slate-600">{{ t('c_operator_pages.operations.not_set', 'not set') }}</span>
                            <span v-else class="text-slate-700">{{ t('c_operator_pages.operations.configured', 'configured') }}</span>
                            <span v-if="it.dev_default" class="ml-1 rounded bg-rose-100 px-1.5 py-0.5 text-[10px] font-medium text-rose-700">{{ t('c_operator_pages.operations.dev_default', 'dev default — rotate') }}</span>
                        </span>

                        <!-- Editable boolean (federation enabled) -->
                        <button v-else-if="it.control === 'bool'" type="button" @click="toggleBool(it)"
                                class="rounded border px-2 py-0.5 text-xs font-medium"
                                :class="it.value === 'yes' ? 'border-emerald-300 bg-emerald-50 text-emerald-800' : 'border-slate-300 bg-white text-slate-600'">
                            {{ it.value === 'yes' ? t('c_operator_pages.operations.enabled_disable', 'Enabled — disable') : t('c_operator_pages.operations.disabled_enable', 'Disabled — enable') }}
                        </button>

                        <!-- Editable int / text (instant-tier override) -->
                        <template v-else-if="it.editable">
                            <input :value="draftVal(it)" @input="drafts[it.key] = $event.target.value" :aria-label="it.label"
                                   :type="it.control === 'int' ? 'number' : 'text'"
                                   class="w-44 rounded border border-slate-300 px-2 py-0.5 font-mono text-sm" />
                            <button type="button" @click="saveTuning(it)"
                                    class="rounded bg-sky-700 px-2 py-0.5 text-xs font-medium text-white hover:bg-sky-800">{{ t('c_operator_pages.operations.save', 'Save') }}</button>
                            <span v-if="it.overridden" class="rounded bg-emerald-100 px-1.5 py-0.5 text-[10px] font-medium text-emerald-800">{{ t('c_operator_pages.operations.overridden', 'overridden') }}</span>
                            <button v-if="it.overridden" type="button" @click="resetTuning(it)" class="text-xs text-slate-500 underline hover:text-slate-700">{{ t('c_operator_pages.operations.reset', 'reset') }}</button>
                        </template>

                        <!-- Read-only -->
                        <span v-else class="break-all font-mono text-sm" :class="it.configured ? 'text-slate-800' : 'text-slate-600'">
                            {{ it.configured ? it.value : '—' }}
                        </span>

                        <span v-if="it.note" class="basis-full pl-48 text-xs text-slate-600">{{ it.note }}</span>
                    </div>
                </div>

                <!-- Installed certs -->
                <div v-if="s.certs" class="mt-4">
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('c_operator_pages.operations.installed_certs', 'Installed certificates') }}</h3>
                    <p v-if="!s.certs.length" class="mt-1 text-xs text-slate-600">{{ t('c_operator_pages.operations.none_tls', 'None installed under the TLS path.') }}</p>
                    <ul v-else class="mt-1 space-y-1">
                        <li v-for="c in s.certs" :key="c.fqdn" class="flex flex-wrap items-center gap-x-3 text-sm" :class="certClass(c)">
                            <span class="font-mono">{{ c.fqdn }}</span>
                            <span class="text-xs">{{ t('c_operator_pages.operations.cert_expires', { date: fmtDate(c.not_after) }) }}</span>
                            <span v-if="c.expired" class="text-xs font-semibold">{{ t('c_operator_pages.operations.cert_expired', '· EXPIRED') }}</span>
                            <span v-else-if="c.days_left !== null" class="text-xs">{{ t('c_operator_pages.operations.cert_days_left', { days: c.days_left }) }}</span>
                        </li>
                    </ul>
                    <p v-if="s.grants && s.grants.length" class="mt-2 text-xs text-slate-500">
                        {{ t('c_operator_pages.operations.grants_ready', 'Delivered grants ready to issue:') }} <span class="font-mono">{{ s.grants.join(', ') }}</span>
                    </p>
                </div>

                <!-- Broker credentials (write-only — domains/zones only) -->
                <div v-if="s.credentials" class="mt-4">
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('c_operator_pages.operations.dns_credentials', 'DNS credentials') }}</h3>
                    <p v-if="!s.credentials.length" class="mt-1 text-xs text-slate-600">
                        {{ t('c_operator_pages.operations.no_dns_cred', 'No DNS credential stored. Set one (write-only) on the Federation console.') }}
                    </p>
                    <ul v-else class="mt-1 space-y-1">
                        <li v-for="c in s.credentials" :key="c.domain" class="flex flex-wrap items-center gap-x-3 text-sm text-slate-700">
                            <span class="font-mono">{{ c.domain }}</span>
                            <span class="text-xs text-slate-500">{{ t('c_operator_pages.operations.zone', { id: c.zone_id || '—' }) }}</span>
                            <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium uppercase text-slate-600">{{ c.source }}</span>
                            <span class="text-[10px] text-emerald-700">{{ t('c_operator_pages.operations.token_stored', 'token stored ✓') }}</span>
                        </li>
                    </ul>
                </div>

                <!-- Capability channels -->
                <div v-if="s.channels && s.channels.length" class="mt-4">
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('c_operator_pages.operations.capability_channels', 'Capability channels') }}</h3>
                    <ul class="mt-1 flex flex-wrap gap-2">
                        <li v-for="ch in s.channels" :key="ch.capability" class="flex items-center gap-1.5 text-sm">
                            <span class="font-mono text-xs text-slate-700">{{ ch.capability }}</span>
                            <span class="rounded px-1.5 py-0.5 text-[10px] font-medium" :class="stateBadge(ch.state)">{{ ch.state || t('c_operator_pages.operations.unknown', 'unknown') }}</span>
                        </li>
                    </ul>
                </div>
            </section>

            <!-- Phase 3 — restart-tier host-apply (LiveKit ICE networking) -->
            <section class="rounded-lg border border-amber-200 bg-amber-50/40 p-5">
                <h2 class="text-sm font-semibold text-slate-900">{{ t('c_operator_pages.operations.apply_restart_title', 'Apply restart-tier changes — LiveKit networking') }}</h2>
                <p class="mt-1 max-w-2xl text-xs text-slate-600">
                    {{ t('c_operator_pages.operations.apply_restart_before', 'These knobs are env-baked, so applying rewrites') }} <code class="font-mono" data-no-i18n>.env</code> {{ t('c_operator_pages.operations.apply_restart_mid', "and recreates the container — which the app can't do from inside itself. A host-side supervisor does it: run") }}
                    <code class="font-mono" data-no-i18n>python3 scripts/ops/infra_supervisor.py</code> {{ t('c_operator_pages.operations.apply_restart_after', 'on the host first (see the LiveKit runbook). No secrets are applyable here.') }}
                </p>

                <div class="mt-3 space-y-2">
                    <div v-for="a in APPLYABLE" :key="a.key" class="flex flex-wrap items-center gap-2">
                        <span class="w-56 shrink-0 text-sm text-slate-700">{{ a.label }}</span>
                        <input :value="applyVal(a)" @input="applyDrafts[a.key] = $event.target.value" :aria-label="a.label"
                               class="w-72 rounded border border-slate-300 px-2 py-0.5 font-mono text-sm" />
                    </div>
                </div>
                <button type="button" @click="submitApply"
                        class="mt-3 rounded bg-amber-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-amber-700">
                    {{ t('c_operator_pages.operations.apply_recreate', 'Apply & recreate') }}
                </button>

                <div v-if="applyState && applyState.lifecycle !== 'idle'"
                     class="mt-3 rounded border px-3 py-2 text-sm"
                     :class="{
                        'border-sky-200 bg-sky-50 text-sky-800': ['pending', 'applying'].includes(applyState.lifecycle),
                        'border-emerald-200 bg-emerald-50 text-emerald-800': applyState.lifecycle === 'applied',
                        'border-rose-200 bg-rose-50 text-rose-700': applyState.lifecycle === 'failed',
                     }">
                    <span class="font-medium">{{ applyLabel }}</span>
                    <span v-if="applyState.lifecycle === 'pending' && !applyState.supervisor_seen">
                        {{ t('c_operator_pages.operations.waiting_supervisor_before', '— waiting for the host supervisor. Is') }} <code class="font-mono" data-no-i18n>scripts/ops/infra_supervisor.py</code> {{ t('c_operator_pages.operations.waiting_supervisor_after', 'running on the host?') }}
                    </span>
                    <span v-if="applyState.error"> — {{ applyState.error }}</span>
                </div>
            </section>

            <p class="text-xs text-slate-600">
                {{ t('c_operator_pages.operations.footer_before', 'Instant-tier knobs are edited in place (applied on the next request). Restart-tier LiveKit networking is applied via the host supervisor above. Secret rotation is intentionally not yet wired — it is gated on the credential-security pass; rotate via') }} <code class="font-mono" data-no-i18n>matrix:setup</code> {{ t('c_operator_pages.operations.footer_after', 'for now.') }}
            </p>
        </template>
    </div>
</template>
