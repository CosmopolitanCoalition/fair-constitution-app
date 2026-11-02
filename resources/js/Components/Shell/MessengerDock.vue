<script setup>
/**
 * The floating messenger. One launcher with an unread count, and a panel that lists every private
 * room and followed public square, opens one inside the panel, and keeps it open across pages.
 * State and polling live in lib/messengerStore.js. Where the person stopped reading is saved, so a
 * closed window comes back to the same conversation.
 */
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { MessageSquare, X } from 'lucide-vue-next';
import * as store from '@/lib/messengerStore.js';

const { t, locale } = useI18n();
const { panelOpen, rows, active, following, reachable, sendError } = store;
const thread = store.activeThread;
const draft = ref('');
const threadEl = ref(null);
const options = ref([]);
const adding = ref(false);

const current = computed(() => store.rowFor(active.value));
const messages = computed(() => thread.value?.messages ?? []);
const label = (sender) => String(sender || '').replace(/^@/, '').split(':')[0];
const mine = (m) => !!thread.value && m.sender === thread.value.my_mxid;
const when = (ms) => (ms ? new Date(Number(ms)).toLocaleTimeString(locale.value, { hour: '2-digit', minute: '2-digit' }) : '');
const ago = (ms) => (ms ? new Date(Number(ms)).toLocaleDateString(locale.value, { month: 'short', day: 'numeric' }) : '');
const unfollowable = (row) => row.kind === 'commons' && following.value.includes(row.key);
const addable = computed(() => options.value.filter((o) => !following.value.includes(o.key)));

watch(() => [messages.value.length, active.value, panelOpen.value], () => nextTick(() => { if (threadEl.value) threadEl.value.scrollTop = threadEl.value.scrollHeight; }));

async function submit() {
    const body = draft.value.trim();
    if (!body || !active.value) return;
    if (await store.send(active.value, body)) draft.value = '';
}
async function showAdd() {
    adding.value = !adding.value;
    if (adding.value) options.value = await store.publicOptions();
}
onMounted(() => { void store.start(); });
</script>

<template>
    <div class="msg-dock">
        <section v-if="panelOpen" class="msg-panel" role="dialog" :aria-label="t('c_dock.messages', 'Messages')">
            <header class="msg-panel__head">
                <button v-if="active" type="button" class="btn btn--ghost btn--sm" @click="store.showList()">{{ t('c_dock.back', 'Back') }}</button>
                <strong class="msg-panel__title">{{ active ? (current?.title || thread?.conversation?.title) : t('c_dock.messages', 'Messages') }}</strong>
                <Link v-if="active && (current?.href || thread?.conversation?.href)" :href="current?.href || thread.conversation.href" class="btn btn--ghost btn--sm">{{ t('c_dock.open_page', 'Open page') }}</Link>
                <button type="button" class="btn btn--ghost btn--sm" :aria-label="t('c_dock.close', 'Close messages')" @click="store.togglePanel()"><X :size="16" aria-hidden="true" /></button>
            </header>

            <p v-if="!reachable" class="msg-note" role="status">{{ t('c_dock.offline', 'Messages are not reachable right now. They will refresh when the connection returns.') }}</p>

            <template v-if="!active">
                <p v-if="!rows.length" class="msg-note">{{ t('c_dock.none', 'No conversations yet. Start one from Messages, or follow a public room below.') }}</p>
                <ul class="msg-list">
                    <li v-for="row in rows" :key="row.key">
                        <button type="button" class="msg-row" @click="store.openConversation(row.key)">
                            <span class="msg-row__top">
                                <strong>{{ row.title }}</strong>
                                <span v-if="row.kind === 'commons'" class="msg-tag">{{ t('c_dock.public', 'Public') }}</span>
                                <span v-if="row.unread" class="msg-badge" :aria-label="t('c_dock.unread', { count: row.unread })">{{ row.unread }}</span>
                                <span class="msg-when">{{ ago(row.last_at) }}</span>
                            </span>
                            <span class="msg-row__preview">{{ row.preview || t('c_dock.no_messages', 'No messages yet') }}</span>
                        </button>
                        <button v-if="unfollowable(row)" type="button" class="btn btn--ghost btn--sm" @click="store.unfollow(row.key)">{{ t('c_dock.unfollow', 'Unfollow') }}</button>
                    </li>
                </ul>
                <div class="msg-foot">
                    <button type="button" class="btn btn--secondary btn--sm" :aria-expanded="adding" @click="showAdd">{{ t('c_dock.follow_public', 'Follow a public room') }}</button>
                    <Link href="/civic/rooms/new" class="btn btn--ghost btn--sm">{{ t('c_dock.new_message', 'New message') }}</Link>
                    <ul v-if="adding" class="msg-options">
                        <li v-if="!addable.length" class="msg-note">{{ t('c_dock.no_public', 'No other public rooms to follow.') }}</li>
                        <li v-for="o in addable" :key="o.key"><button type="button" class="btn btn--ghost btn--sm" @click="store.follow(o.key)">{{ o.title }}</button></li>
                    </ul>
                </div>
            </template>

            <template v-else>
                <div ref="threadEl" class="msg-thread" aria-live="polite">
                    <p v-if="!messages.length" class="msg-note">{{ t('c_dock.empty_thread', 'No messages yet.') }}</p>
                    <div v-for="m in messages" :key="m.event_id" class="msg-bubble" :class="{ 'msg-bubble--mine': mine(m) }">
                        <span v-if="!mine(m)" class="msg-from">{{ label(m.sender) }}</span>
                        <span class="msg-text">{{ m.body }}</span>
                        <span class="msg-when">{{ when(m.at) }}</span>
                    </div>
                </div>
                <form class="msg-compose" @submit.prevent="submit">
                    <label class="sr-only" for="dock-draft">{{ t('c_dock.message', 'Message') }}</label>
                    <textarea id="dock-draft" v-model="draft" rows="2" maxlength="20000" :placeholder="t('c_dock.placeholder', 'Write a message…')"
                        @keydown.enter.exact.prevent="submit"></textarea>
                    <button type="submit" class="btn btn--primary btn--sm" :disabled="store.isSending.value || !draft.trim()">{{ t('c_dock.send', 'Send') }}</button>
                </form>
                <p v-if="sendError" class="msg-note field-error" role="alert">{{ sendError }}</p>
            </template>
        </section>

        <button type="button" class="msg-launch btn btn--secondary" :aria-expanded="panelOpen" @click="store.togglePanel()">
            <MessageSquare :size="16" aria-hidden="true" />
            {{ t('c_dock.messages', 'Messages') }}
            <span v-if="store.unreadTotal.value" class="msg-badge" :aria-label="t('c_dock.unread', { count: store.unreadTotal.value })">{{ store.unreadTotal.value }}</span>
        </button>
    </div>
</template>

<style scoped>
.msg-dock { display: flex; flex-direction: column; align-items: flex-end; gap: 0.5rem; }
.msg-launch { display: inline-flex; align-items: center; gap: 0.4rem; box-shadow: 0 4px 16px rgb(0 0 0 / 0.3); }
.msg-panel { background: var(--gov-surface, #101827); color: var(--gov-fg, #f3f4f6); border: 1px solid var(--gov-border, #334155); border-radius: 0.75rem;
    box-shadow: 0 8px 28px rgb(0 0 0 / 0.4); inline-size: min(24rem, calc(100vw - 2rem)); max-block-size: min(32rem, 70vh); display: flex; flex-direction: column; overflow: hidden; }
.msg-panel__head { display: flex; align-items: center; gap: 0.4rem; padding: 0.5rem 0.6rem; border-block-end: 1px solid var(--gov-border, #334155); }
.msg-panel__title { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.msg-note { margin: 0; padding: 0.6rem 0.75rem; font-size: 0.85rem; opacity: 0.85; }
.msg-list { list-style: none; margin: 0; padding: 0; overflow-y: auto; }
.msg-list li { display: flex; align-items: center; border-block-end: 1px solid var(--gov-border, #334155); }
.msg-row { flex: 1; min-inline-size: 0; text-align: start; background: none; border: 0; color: inherit; padding: 0.6rem 0.75rem; cursor: pointer; display: grid; gap: 0.15rem; }
.msg-row:hover, .msg-row:focus-visible { background: rgb(255 255 255 / 0.06); }
.msg-row__top { display: flex; align-items: center; gap: 0.4rem; min-inline-size: 0; }
.msg-row__top strong { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.msg-row__preview { font-size: 0.85rem; opacity: 0.75; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.msg-tag { font-size: 0.7rem; border: 1px solid currentColor; border-radius: 999px; padding: 0 0.4rem; opacity: 0.8; }
.msg-badge { background: #2563eb; color: #fff; border-radius: 999px; font-size: 0.72rem; padding: 0 0.4rem; min-inline-size: 1.2rem; text-align: center; }
.msg-when { margin-inline-start: auto; font-size: 0.72rem; opacity: 0.6; }
.msg-foot { padding: 0.5rem 0.6rem; display: flex; flex-wrap: wrap; gap: 0.4rem; }
.msg-options { list-style: none; margin: 0; padding: 0; inline-size: 100%; display: flex; flex-wrap: wrap; gap: 0.3rem; }
.msg-thread { flex: 1; overflow-y: auto; padding: 0.6rem; display: flex; flex-direction: column; gap: 0.4rem; min-block-size: 8rem; }
.msg-bubble { align-self: flex-start; max-inline-size: 85%; background: rgb(255 255 255 / 0.08); border-radius: 0.75rem; padding: 0.35rem 0.6rem; display: grid; gap: 0.1rem; overflow-wrap: anywhere; }
.msg-bubble--mine { align-self: flex-end; background: #1d4ed8; color: #fff; }
.msg-from { font-size: 0.72rem; opacity: 0.7; }
.msg-text { white-space: pre-wrap; }
.msg-compose { display: flex; gap: 0.4rem; padding: 0.5rem 0.6rem; border-block-start: 1px solid var(--gov-border, #334155); align-items: flex-end; }
.msg-compose textarea { flex: 1; resize: none; background: rgb(255 255 255 / 0.06); color: inherit; border: 1px solid var(--gov-border, #334155); border-radius: 0.5rem; padding: 0.4rem; }
@media (max-width: 40rem) { .msg-panel { inline-size: calc(100vw - 1rem); max-block-size: 65vh; } }
</style>
