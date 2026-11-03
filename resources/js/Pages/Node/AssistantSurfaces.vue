<script setup>
import { useI18n } from 'vue-i18n';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import NodeNav from '@/Components/Node/NodeNav.vue';
import PreviewBanner from '@/Components/Node/PreviewBanner.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import { useNodeChoices } from '@/lib/nodeChoices.js';
import { useAssistant } from '@/composables/useAssistant.js';

const props = defineProps({ surfaces: Array, levels: Array, external: Array });
const { t } = useI18n();
const { open, speechSupported } = useAssistant();
const choices = useNodeChoices('surfaces', { on: Object.fromEntries(props.surfaces.map(s => [s.id, s.status !== 'planned'])), satellites: [], draft: { name: '', room: '' } });
const tone = s => ({ built: 'success', partial: 'warning', planned: 'neutral' })[s] || 'neutral';
function pair() {
    const d = choices.value.draft;
    if (!d.name.trim()) return;
    choices.value.satellites.push({ id: Date.now().toString(36), name: d.name.trim(), room: d.room.trim(), state: 'waiting to pair' });
    choices.value.draft = { name: '', room: '' };
}
const drop = id => { choices.value.satellites = choices.value.satellites.filter(s => s.id !== id); };
</script>

<template>
    <PageScaffold :title="t('c_node.surfaces.title', 'Assistant surfaces')">
        <template #intro>{{ t('c_node.surfaces.intro', 'Where you can speak or type to the assistant. Surfaces differ in how they know who is asking. The tools are the same.') }}</template>
        <NodeNav current="surfaces" />
        <PreviewBanner />

        <section class="app-setting-card" aria-labelledby="sf-list">
            <h2 id="sf-list">{{ t('c_node.surfaces.list', 'Surfaces') }}</h2>
            <ul>
                <li v-for="s in surfaces" :key="s.id">
                    <label><input type="checkbox" v-model="choices.on[s.id]"> <strong>{{ s.name }}</strong></label>
                    <StatusBadge :tone="tone(s.status)">{{ s.status }}</StatusBadge>
                    <span class="muted"> {{ s.level }} · {{ s.note }}</span>
                </li>
            </ul>
            <p><button type="button" class="btn btn--primary" @click="open()">{{ t('c_node.assistant.open', 'Open the assistant drawer') }}</button></p>
            <p class="muted">{{ speechSupported ? t('c_node.surfaces.stt_yes', 'This browser can turn speech into text. The drawer has a microphone button.') : t('c_node.surfaces.stt_no', 'This browser has no built-in speech to text. Type to the assistant instead.') }}</p>
        </section>

        <section class="app-setting-card" aria-labelledby="sf-sat">
            <h2 id="sf-sat">{{ t('c_node.surfaces.satellites', 'Voice satellites and screens') }}</h2>
            <form @submit.prevent="pair">
                <label>{{ t('c_node.surfaces.sat_name', 'Name') }} <input v-model="choices.draft.name" maxlength="60" placeholder="Jarvis"></label>
                <label>{{ t('c_node.surfaces.sat_room', 'Room') }} <input v-model="choices.draft.room" maxlength="60"></label>
                <button class="btn btn--secondary">{{ t('c_node.surfaces.pair', 'Add a device to pair') }}</button>
            </form>
            <ul>
                <li v-for="s in choices.satellites" :key="s.id">{{ s.name }} <span class="muted">{{ s.room }} · {{ s.state }}</span>
                    <button type="button" class="btn btn--secondary btn--sm" @click="drop(s.id)">{{ t('c_node.remove', 'Remove') }}</button></li>
            </ul>
        </section>

        <section class="app-setting-card" aria-labelledby="sf-lv">
            <h2 id="sf-lv">{{ t('c_node.surfaces.levels', 'Who the surface proves is asking') }}</h2>
            <ul><li v-for="l in levels" :key="l.id"><strong>{{ l.id }}</strong> {{ l.label }} <span class="muted">{{ l.note }}</span></li></ul>
        </section>

        <section class="app-setting-card" aria-labelledby="sf-ext">
            <h2 id="sf-ext">{{ t('c_node.surfaces.external', 'Outside channels that can reach it') }}</h2>
            <ul>
                <li v-for="e in external" :key="e.id"><strong>{{ e.name }}</strong> <span class="muted">{{ e.kind }} · {{ e.how }}</span>
                    <StatusBadge :tone="tone(e.status)">{{ e.status }}</StatusBadge></li>
            </ul>
        </section>
    </PageScaffold>
</template>
