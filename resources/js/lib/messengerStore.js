/* ============================================================================
   CGA — lib/messengerStore.js
   The floating messenger's state, kept outside any page so a conversation stays open
   while the person moves around the app. Private rooms and followed public squares
   share one list. What is open, which one is active, and where the person stopped
   reading are saved to the server (GET/PUT /messenger/state) so a closed window or a
   second device returns to the same place. Message text stays in Matrix.
   ============================================================================ */

import { computed, reactive, ref } from 'vue';
import { csrfFetch } from '@/lib/csrf.js';

const LIST_MS = 30000;
const LIST_OPEN_MS = 15000;
const THREAD_MS = 5000;
const SAVE_DELAY_MS = 800;

export const panelOpen = ref(false);
export const loaded = ref(false);
export const reachable = ref(true);
export const sendError = ref('');
export const rows = ref([]); // conversation list rows from the server
export const open = ref([]); // keys the person has open (recently used first)
export const active = ref(null); // the key shown in the panel, or null for the list
export const following = ref([]);
const reads = reactive({});
const threads = reactive({}); // key -> { conversation, messages, my_mxid, reachable }
const sending = ref(false);

let started = false;
let listTimer = null;
let threadTimer = null;
let saveTimer = null;
let dirty = false;

const json = { Accept: 'application/json', 'Content-Type': 'application/json' };

async function get(path) {
    const response = await fetch(path, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    if (!response.ok) throw new Error(String(response.status));
    return response.json();
}

export const unreadTotal = computed(() => rows.value.reduce((n, r) => n + (r.unread || 0), 0));
export const activeThread = computed(() => (active.value ? threads[active.value] ?? null : null));
export const rowFor = (key) => rows.value.find((r) => r.key === key) ?? null;

function queueSave() {
    dirty = true;
    if (saveTimer) return;
    saveTimer = setTimeout(flushSave, SAVE_DELAY_MS);
}

export async function flushSave() {
    clearTimeout(saveTimer);
    saveTimer = null;
    if (!dirty) return;
    dirty = false;
    try {
        await csrfFetch('/messenger/state', {
            method: 'PUT', headers: json,
            body: JSON.stringify({ open: open.value, active: active.value, following: following.value, reads: { ...reads } }),
        });
    } catch { dirty = true; /* try again on the next change */ }
}

export async function refreshList() {
    try {
        const data = await get('/messenger/conversations');
        rows.value = data.rows ?? [];
        reachable.value = data.reachable !== false;
    } catch { reachable.value = false; }
}

export async function refreshThread(key = active.value) {
    if (!key) return;
    try {
        const data = await get(`/messenger/conversations/${encodeURIComponent(key)}/messages`);
        threads[key] = data;
        markRead(key);
    } catch (e) {
        // A room the person can no longer reach drops out of the open set.
        if (String(e.message) === '404') closeConversation(key);
    }
}

/** Move the read mark to the newest message in a thread the person is looking at. */
export function markRead(key) {
    const t = threads[key];
    const last = t?.messages?.length ? Number(t.messages[t.messages.length - 1].at || 0) : 0;
    if (!last || last <= (reads[key] || 0)) return;
    reads[key] = last;
    const row = rowFor(key);
    if (row) row.unread = 0;
    queueSave();
}

export function openConversation(key) {
    open.value = [key, ...open.value.filter((k) => k !== key)].slice(0, 8);
    active.value = key;
    panelOpen.value = true;
    queueSave();
    void refreshThread(key);
    schedule();
}

export function showList() {
    active.value = null;
    queueSave();
    schedule();
}

export function closeConversation(key) {
    open.value = open.value.filter((k) => k !== key);
    if (active.value === key) active.value = null;
    delete threads[key];
    queueSave();
}

export function togglePanel() {
    panelOpen.value = !panelOpen.value;
    if (panelOpen.value) { void refreshList(); if (active.value) void refreshThread(); }
    schedule();
}

export async function send(key, body) {
    sendError.value = '';
    sending.value = true;
    try {
        const response = await csrfFetch(`/messenger/conversations/${encodeURIComponent(key)}/messages`, { method: 'POST', headers: json, body: JSON.stringify({ body }) });
        if (!response.ok) throw new Error((await response.json().catch(() => ({}))).message || 'send_failed');
        await refreshThread(key);
        void refreshList();
        return true;
    } catch (e) {
        sendError.value = e.message === 'send_failed' ? '' : e.message;
        return false;
    } finally { sending.value = false; }
}
export const isSending = computed(() => sending.value);

export async function publicOptions() {
    try { return (await get('/messenger/public-options')).data ?? []; } catch { return []; }
}
export function follow(key) {
    if (!following.value.includes(key)) following.value = [...following.value, key];
    queueSave();
    setTimeout(refreshList, 300);
}
export function unfollow(key) {
    following.value = following.value.filter((k) => k !== key);
    closeConversation(key);
    queueSave();
    setTimeout(refreshList, 300);
}

function schedule() {
    clearTimeout(listTimer); clearTimeout(threadTimer);
    if (!started || (typeof document !== 'undefined' && document.hidden)) return;
    const loopList = async () => { await refreshList(); listTimer = setTimeout(loopList, panelOpen.value ? LIST_OPEN_MS : LIST_MS); };
    listTimer = setTimeout(loopList, panelOpen.value ? LIST_OPEN_MS : LIST_MS);
    if (panelOpen.value && active.value) {
        const loopThread = async () => { await refreshThread(); threadTimer = setTimeout(loopThread, THREAD_MS); };
        threadTimer = setTimeout(loopThread, THREAD_MS);
    }
}

/** Load the saved state and begin polling. Safe to call more than once. */
export async function start() {
    if (started) return;
    started = true;
    try {
        const data = await get('/messenger/state');
        const s = data.state ?? {};
        open.value = Array.isArray(s.open) ? s.open : [];
        following.value = Array.isArray(s.following) ? s.following : [];
        Object.assign(reads, s.reads ?? {});
        active.value = s.active && open.value.includes(s.active) ? s.active : null;
    } catch { /* start empty */ }
    loaded.value = true;
    await refreshList();
    if (active.value) void refreshThread(active.value);
    schedule();
    if (typeof document !== 'undefined') {
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) { clearTimeout(listTimer); clearTimeout(threadTimer); void flushSave(); return; }
            void refreshList(); if (panelOpen.value && active.value) void refreshThread();
            schedule();
        });
        window.addEventListener('online', () => { void refreshList(); if (active.value) void refreshThread(); });
        window.addEventListener('pagehide', () => { void flushSave(); });
    }
}
