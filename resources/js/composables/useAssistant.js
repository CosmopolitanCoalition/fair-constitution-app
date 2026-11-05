/* The assistant drawer state, shared by the nav button, the drawer and the node pages.
   The drawer is on every page. It carries the page the person is on, so the assistant
   can offer help that fits. Each message goes to the node (POST /node/assistant/chat). The
   thread is also kept in this browser. */
import { computed, ref, watch } from 'vue';

const STORE = 'cga.assistant.thread';
const isOpen = ref(false);
const listening = ref(false);
const thread = ref(load());

function load() {
    try {
        const raw = window.localStorage.getItem(STORE);
        const parsed = raw ? JSON.parse(raw) : [];
        return Array.isArray(parsed) ? parsed.slice(-60) : [];
    } catch {
        return [];
    }
}
watch(thread, v => {
    try { window.localStorage.setItem(STORE, JSON.stringify(v.slice(-60))); } catch { /* storage blocked */ }
}, { deep: true });

/* Suggested starts by area. The first matching prefix wins. */
const SUGGESTIONS = [
    ['/workspace/tasks', ['Add a task for tomorrow', 'What is overdue?']],
    ['/workspace/calendar', ['What is on today?', 'Find a free hour this week']],
    ['/workspace/contacts', ['Who needs a follow-up?', 'Find a contact']],
    ['/workspace/inbox', ['Summarize my inbox', 'Draft a reply']],
    ['/connections', ['Which keys have I not used lately?']],
    ['/node/data', ['What may the assistant read here?', 'Who can see my calendar?']],
    ['/node/sharing', ['Explain this link grant', 'What am I lending?']],
    ['/node/bundles', ['Which tools fit this box?', 'What does monitoring add?']],
    ['/node', ['What does this node host?']],
    ['/legislature', ['Summarize the bill in front of me']],
    ['/elections', ['Explain my ballot']],
    ['/organizations', ['What are my roles here?']],
];

function speechCtor() {
    return typeof window !== 'undefined' ? (window.SpeechRecognition || window.webkitSpeechRecognition || null) : null;
}

let recognizer = null;

export function useAssistant() {
    const speechSupported = computed(() => !!speechCtor());

    function open() { isOpen.value = true; }
    function close() { isOpen.value = false; stopListening(); }
    function toggle() { isOpen.value ? close() : open(); }

    function suggestionsFor(path) {
        const hit = SUGGESTIONS.find(([prefix]) => path === prefix || path.startsWith(prefix + '/') || path.startsWith(prefix + '?'));
        return hit ? hit[1] : ['What can you do on this page?', 'What may you read for me?'];
    }

    /* One turn: show the person's words, then the node's reply (W-0523). A failed call
       says so in the thread and nothing else is lost. */
    async function send(text, context) {
        const body = (text || '').trim();
        if (!body) return;
        const history = thread.value.filter(m => !m.pending).slice(-12).map(m => ({ role: m.role, text: m.text }));
        thread.value.push({ id: Date.now().toString(36) + thread.value.length, role: 'user', text: body, at: new Date().toISOString(), context });
        thread.value.push({ id: Date.now().toString(36) + 'a' + thread.value.length, role: 'assistant', at: new Date().toISOString(), pending: true, text: '' });
        const slot = thread.value[thread.value.length - 1];
        try {
            const { data } = await window.axios.post('/node/assistant/chat', { message: body, history });
            slot.text = data.text;
            slot.mode = data.mode;
            slot.tool = data.tool;
            slot.denied = data.denied;
        } catch {
            slot.text = 'The node did not answer. Check your connection and try again.';
        }
        slot.pending = false;
    }

    function clear() { thread.value = []; }

    /* Browser speech to text. Fills the composer through onText. */
    function startListening(onText, lang) {
        const C = speechCtor();
        if (!C || listening.value) return false;
        recognizer = new C();
        recognizer.lang = lang || document.documentElement.lang || 'en-US';
        recognizer.interimResults = true;
        recognizer.continuous = false;
        recognizer.onresult = e => onText(Array.from(e.results).map(r => r[0].transcript).join(' '));
        recognizer.onend = () => { listening.value = false; recognizer = null; };
        recognizer.onerror = () => { listening.value = false; recognizer = null; };
        listening.value = true;
        recognizer.start();
        return true;
    }
    function stopListening() {
        try { recognizer?.stop(); } catch { /* already stopped */ }
        listening.value = false;
    }

    return { isOpen, thread, listening, speechSupported, open, close, toggle, send, clear, suggestionsFor, startListening, stopListening };
}
