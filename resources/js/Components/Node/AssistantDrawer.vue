<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Icon from '@/Components/Ui/Icon.vue';
import { useAssistant } from '@/composables/useAssistant.js';

const { t } = useI18n();
const page = usePage();
const { isOpen, thread, listening, speechSupported, close, send, clear, suggestionsFor, startListening, stopListening } = useAssistant();
const draft = ref('');
const log = ref(null);
const path = computed(() => (page.url || '').split('#')[0]);
const title = computed(() => page.props.surface?.title || document.title || path.value);
const suggestions = computed(() => suggestionsFor(path.value.split('?')[0]));

function submit() {
    send(draft.value, { path: path.value, title: title.value });
    draft.value = '';
}
function pick(s) { draft.value = s; }
function mic() {
    if (listening.value) { stopListening(); return; }
    startListening(text => { draft.value = text; });
}
watch([() => thread.value.length, isOpen], async () => {
    await nextTick();
    if (log.value) log.value.scrollTop = log.value.scrollHeight;
});
function onKey(e) { if (e.key === 'Escape') close(); }
</script>

<template>
    <aside v-if="isOpen" class="assistant-drawer" role="complementary" :aria-label="t('c_node.drawer.label', 'Assistant')" @keydown="onKey">
        <header>
            <h2>{{ t('c_node.drawer.title', 'Assistant') }}</h2>
            <button type="button" class="btn btn--ghost btn--sm" :aria-label="t('c_node.drawer.close', 'Close the assistant')" @click="close"><Icon name="x" size="sm" /></button>
        </header>
        <p class="drawer-context muted">{{ t('c_node.drawer.here', 'You are on') }}: <strong>{{ title }}</strong></p>
        <p class="drawer-status" role="status">
            {{ t('c_node.drawer.status', 'Not connected yet. It will use only what you may use.') }}
            <Link href="/node/assistant">{{ t('c_node.drawer.setup', 'Set it up') }}</Link>
        </p>
        <div ref="log" class="drawer-log" aria-live="polite">
            <p v-if="!thread.length" class="muted">{{ t('c_node.drawer.empty', 'Ask in your own words, or pick a start below.') }}</p>
            <div v-for="m in thread" :key="m.id" class="drawer-msg" :class="'drawer-msg--' + m.role">
                <span class="muted">{{ m.role === 'user' ? t('c_node.drawer.you', 'You') : t('c_node.drawer.assistant', 'Assistant') }}</span>
                <p>{{ m.text }}</p>
            </div>
        </div>
        <div class="drawer-suggest">
            <button v-for="s in suggestions" :key="s" type="button" class="btn btn--secondary btn--sm" @click="pick(s)">{{ s }}</button>
        </div>
        <form class="drawer-form" @submit.prevent="submit">
            <label class="visually-hidden" for="assistant-input">{{ t('c_node.drawer.input', 'Message to the assistant') }}</label>
            <textarea id="assistant-input" v-model="draft" rows="2" :placeholder="t('c_node.drawer.placeholder', 'Type or use the microphone')" @keydown.enter.exact.prevent="submit" />
            <div class="drawer-actions">
                <button v-if="speechSupported" type="button" class="btn btn--secondary btn--sm" :aria-pressed="listening" @click="mic">
                    {{ listening ? t('c_node.drawer.stop', 'Stop listening') : t('c_node.drawer.mic', 'Speak') }}
                </button>
                <button class="btn btn--primary btn--sm" :disabled="!draft.trim()">{{ t('c_node.drawer.send', 'Send') }}</button>
                <button v-if="thread.length" type="button" class="btn btn--ghost btn--sm" @click="clear">{{ t('c_node.drawer.clear', 'Clear') }}</button>
            </div>
        </form>
    </aside>
</template>

<style scoped>
.assistant-drawer { position: fixed; inset-block: 0; inset-inline-end: 0; inline-size: min(26rem, 100vw); z-index: 60; display: flex; flex-direction: column; gap: .6rem;
    padding: var(--space-4); background: var(--gov-surface); color: var(--gov-fg); border-inline-start: 1px solid var(--gov-border); box-shadow: var(--shadow-lg, 0 0 24px rgb(0 0 0 / .2)); }
.assistant-drawer header { display: flex; align-items: center; justify-content: space-between; }
.assistant-drawer h2 { margin: 0; font-size: var(--text-lg); }
.drawer-status { margin: 0; padding: .4rem .6rem; border-radius: var(--radius-sm); background: var(--gov-surface-2); font-size: var(--text-sm); }
.drawer-log { flex: 1; overflow-y: auto; display: flex; flex-direction: column; gap: .5rem; }
.drawer-msg { padding: .5rem .7rem; border-radius: var(--radius-sm); background: var(--gov-surface-2); }
.drawer-msg--user { background: var(--gov-surface-3, var(--gov-surface-2)); border-inline-start: 3px solid var(--gov-primary); }
.drawer-msg p { margin: .15rem 0 0; white-space: pre-wrap; }
.drawer-suggest { display: flex; flex-wrap: wrap; gap: .35rem; }
.drawer-form textarea { inline-size: 100%; resize: vertical; }
.drawer-actions { display: flex; gap: .4rem; justify-content: flex-end; margin-block-start: .4rem; }
.visually-hidden { position: absolute; inline-size: 1px; block-size: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
</style>
