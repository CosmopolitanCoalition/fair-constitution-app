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
import { onBeforeUnmount, onMounted, ref } from 'vue';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import StageBars from '@/Components/Progress/StageBars.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Card from '@/Components/Ui/Card.vue';
import Stat from '@/Components/Ui/Stat.vue';

defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    stages: { type: Array, default: () => [] },
    world: { type: Object, default: null },
    pollMs: { type: Number, default: 2000 },
});

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
    <PageScaffold :surface="surface" title="Building the world">
        <template #intro>
            <p class="text-sm text-gray-400">
                Every place gets the same institutions before anyone arrives: a chamber, an
                executive, a court, an election board, and somewhere to talk. This is how far
                along that is.
            </p>
        </template>

        <Banner v-if="world?.complete" tone="success" class="mb-4">
            Every stage is full — this world is built and ready for people.
        </Banner>

        <Card v-if="world" class="mb-4">
            <div class="flex flex-wrap gap-6">
                <Stat label="Places with a chamber" :value="fmt(world.legislatures)" />
                <Stat label="Getting institutions" :value="fmt(world.target)" />
                <Stat
                    v-if="world.skipped"
                    label="Nobody lives there"
                    :value="fmt(world.skipped)"
                />
            </div>

            <p class="mt-3 text-xs text-gray-500">
                {{ world.binding_label }}.
                <template v-if="world.skipped">
                    Places with no people and no smaller places inside them get nothing at all —
                    that is deliberate, not missing work.
                </template>
            </p>
        </Card>

        <StageBars :stages="stages" :poll-ms="pollMs" />
    </PageScaffold>
</template>
