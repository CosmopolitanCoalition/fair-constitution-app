<script setup>
/**
 * Build/Progress — how much of this world exists yet.
 *
 * The screen the operator watches while a fresh box builds itself: boundaries
 * become district maps, maps become chambers, chambers get their executive,
 * their court, their election board and their public square. When every bar is
 * full, the world is ready for a player.
 *
 * Bars come from the shared StageBars component, the same one the district
 * mapper and the sim console use — one progress idiom, not three.
 *
 * The poll stays armed the whole time the page is open, including when nothing
 * is running. That is deliberate: a run started from a terminal elsewhere
 * appears here within one tick, and the old conditional arming was exactly the
 * bug that made the district mapper look frozen until someone refreshed.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import StageBars from '@/Components/Progress/StageBars.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import Stat from '@/Components/Ui/Stat.vue';

defineOptions({ layout: AppShellV2 });

const { t } = useI18n();

const props = defineProps({
    surface: { type: Object, required: true },
    stages: { type: Array, default: () => [] },
    world: { type: Object, default: null },
    pollMs: { type: Number, default: 2000 },
    /** Operator-only: /building is public, so the provision action is gated here. */
    canProvision: { type: Boolean, default: false },
});

const page = usePage();
const flash = computed(() => page.props.flash?.status ?? null);

/* Operator door: the UI twin of institutions:provision. Preview counts what is
   missing (a cheap dry run); a real run queues the set-based job and the poll
   above animates the bars. */
const provisionForm = useForm({ dry_run: true });
function preview() {
    provisionForm.dry_run = true;
    provisionForm.post('/building/provision', { preserveScroll: true });
}
function provision() {
    provisionForm.dry_run = false;
    provisionForm.post('/building/provision', { preserveScroll: true });
}

const stages = ref(props.stages);
const world = ref(props.world);
let timer = null;

async function poll() {
    try {
        const res = await fetch('/api/build/progress', {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        if (!res.ok) return;
        const data = await res.json();
        stages.value = data.stages ?? [];
        world.value = data.world ?? null;
    } catch {
        // A dropped poll is not an error worth showing — the next tick retries.
    }
}

onMounted(() => {
    poll();
    timer = setInterval(poll, props.pollMs);
});

onBeforeUnmount(() => {
    if (timer) clearInterval(timer);
});

const fmt = (n) => Number(n ?? 0).toLocaleString();
</script>

<template>
    <PageScaffold :surface="surface" :title="t('c_jurisdictions.progress.title', 'Building the world')">
        <template #intro>
            <p class="text-sm text-gray-400">
                {{ t('c_jurisdictions.progress.intro', 'Every place gets the same institutions before anyone arrives: a chamber, an executive, a court, an election board, and somewhere to talk. This is how far along that is.') }}
            </p>
        </template>

        <Banner v-if="flash" tone="info" class="mb-4">{{ flash }}</Banner>

        <!-- Operator door — the UI twin of institutions:provision. -->
        <Card v-if="canProvision" class="mb-4">
            <h2 class="text-sm font-semibold mb-1">{{ t('c_jurisdictions.progress.provision_title', 'Provision missing institutions') }}</h2>
            <p class="text-sm text-gray-400 mb-3">
                {{ t('c_jurisdictions.progress.provision_body_before', 'Fill every jurisdiction\'s executive, court, election board and civic spaces (the ') }}<code data-no-i18n>institutions:provision</code>{{ t('c_jurisdictions.progress.provision_body_after', ' twin). It is set-based and chunked, so a real run is queued and the bars above fill as it goes — preview first to see what is missing.') }}
            </p>
            <div class="flex gap-2">
                <Btn variant="secondary" size="sm" :disabled="provisionForm.processing" @click="preview">
                    {{ t('c_jurisdictions.progress.preview_missing', 'Preview missing') }}
                </Btn>
                <Btn variant="primary" size="sm" :disabled="provisionForm.processing" @click="provision">
                    {{ provisionForm.processing ? t('c_jurisdictions.progress.working', 'Working…') : t('c_jurisdictions.progress.provision_queue', 'Provision (queue)') }}
                </Btn>
            </div>
        </Card>

        <!-- BUILT is not GOVERNED. A world can be fully built and have nobody
             in it, and that is the correct state until communities hold their
             elections — so the banner never says "ready" without saying how
             many chambers are actually seated. -->
        <Banner v-if="world?.complete && world?.awaiting_election" tone="info" class="mb-4">
            {{ t('c_jurisdictions.progress.awaiting_banner', { seated: fmt(world.seated), total: fmt(world.legislatures) }) }}
        </Banner>

        <Banner v-else-if="world?.complete" tone="success" class="mb-4">
            {{ t('c_jurisdictions.progress.complete_banner', 'Every stage is built and every chamber has members.') }}
        </Banner>

        <Card v-if="world" class="mb-4">
            <div class="flex flex-wrap gap-6">
                <Stat :label="t('c_jurisdictions.progress.stat_chamber', 'Places with a chamber')" :value="fmt(world.legislatures)" />
                <Stat :label="t('c_jurisdictions.progress.stat_members', 'Chambers with members')" :value="fmt(world.seated)" />
                <Stat
                    v-if="world.awaiting_election"
                    :label="t('c_jurisdictions.progress.stat_awaiting', 'Awaiting a first election')"
                    :value="fmt(world.awaiting_election)"
                />
                <Stat
                    v-if="world.skipped"
                    :label="t('c_jurisdictions.progress.stat_empty', 'Nobody lives there')"
                    :value="fmt(world.skipped)"
                />
            </div>

            <p class="mt-3 text-xs" style="color: var(--gov-fg-subtle)">
                {{ world.binding_label }}.
                <template v-if="world.skipped">
                    {{ t('c_jurisdictions.progress.skipped_note', 'Places with no people and no smaller places inside them get nothing at all — that is deliberate, not missing work.') }}
                </template>
            </p>
        </Card>

        <StageBars :stages="stages" :poll-ms="pollMs" />
    </PageScaffold>
</template>
