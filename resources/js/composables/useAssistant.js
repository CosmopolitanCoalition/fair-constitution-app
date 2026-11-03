/* The assistant drawer state, shared by the nav button, the drawer and the node pages.
   The drawer is on every page. It carries the page the person is on, so the assistant
   can offer help that fits. Messages are held in this browser until the assistant
   service is wired (rubric work items W-0516 to W-0520). */
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

    /* The service is not wired. The reply says so and the turn is kept, so nothing is lost. */
    function send(text, context) {
        const body = (text || '').trim();
        if (!body) return;
        thread.value.push({ id: Date.now().toString(36) + thread.value.length, role: 'user', text: body, at: new Date().toISOString(), context });
        thread.value.push({
            id: Date.now().toString(36) + 'a' + thread.value.length, role: 'assistant', at: new Date().toISOString(), pending: true,
            text: 'The assistant service is not connected on this node yet. Your message and the page you were on are saved here. Choose a compute source on the Assistant page to connect it.',
        });
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
