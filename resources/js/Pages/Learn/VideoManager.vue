<script setup>
/**
 * Learn/VideoManager (W-0449) — upload a film's silent master and its audio and
 * caption tracks, and assign films to surfaces for the Learning Drawer.
 *
 * Reading is open to everyone (read-everywhere): the film list renders for any
 * viewer. Publication is an OPERATOR tool, temporary during development
 * (operator ruling 2026-09-16): can.manage is is_operator only. A non-operator
 * sees a read-only preview; the controller is the real gate and refuses a write
 * with 403. File writes are REAL — there is no demo mode here.
 *
 * MaterialManager idiom: PageScaffold, labels bound to ids, role=status/alert
 * live regions, 44 px controls, strings through t().
 */
import { computed, reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';

defineOptions({ layout: AppShellV2 });

const { t } = useI18n();

const props = defineProps({
    surface: { type: Object, required: true },
    can: { type: Object, default: () => ({ manage: false }) },
    videos: { type: Array, default: () => [] },
    languages: { type: Array, default: () => [] },
    surfaces: { type: Array, default: () => [] },
    baseUrl: { type: String, default: null },
    libraryRoot: { type: String, default: '' },
});

const surfaceTitle = computed(() => {
    const map = {};
    for (const s of props.surfaces) map[s.id] = s.title;
    return map;
});

// ── upload form ──────────────────────────────────────────────────────────────
const form = reactive({ title: '', subject: '', summary: '', replaceMaster: false });
const master = ref(null);
const audioTracks = ref([]);   // [{ file, name, lang }]
const captionTracks = ref([]); // [{ file, name, lang }]
const chosenSurfaces = ref([]);
const surfaceQuery = ref('');
const busy = ref(false);
const progress = ref(0);
const error = ref('');
const notice = ref('');

// Read the language from the filename suffix "-<English name>" (longest wins),
// mirroring the server. Empty when nothing matches; the operator picks it then.
function parseLang(filename) {
    const base = String(filename).replace(/\.[^.]+$/, '').toLowerCase();
    let best = '';
    let bestLen = -1;
    for (const l of props.languages) {
        const lower = String(l.name).toLowerCase();
        if ((base === lower || base.endsWith('-' + lower)) && l.name.length > bestLen) {
            best = l.code;
            bestLen = l.name.length;
        }
    }
    return best;
}

function onMaster(event) {
    master.value = event.target.files?.[0] ?? null;
}
function onAudio(event) {
    audioTracks.value = Array.from(event.target.files ?? []).map((file) => ({ file, name: file.name, lang: parseLang(file.name) }));
}
function onCaptions(event) {
    captionTracks.value = Array.from(event.target.files ?? []).map((file) => ({ file, name: file.name, lang: parseLang(file.name) }));
}

const filteredSurfaces = computed(() => {
    const q = surfaceQuery.value.trim().toLowerCase();
    if (!q) return props.surfaces;
    return props.surfaces.filter((s) => s.id.toLowerCase().includes(q) || String(s.title).toLowerCase().includes(q));
});

const canSubmit = computed(() => props.can.manage && !busy.value && form.title.trim().length > 0);

function submit() {
    if (!canSubmit.value) return;
    const data = {
        title: form.title.trim(),
        subject: form.subject.trim(),
        summary: form.summary.trim(),
        replace_master: form.replaceMaster ? 1 : 0,
        surfaces: chosenSurfaces.value,
    };
    if (master.value) data.master = master.value;
    if (audioTracks.value.length) {
        data.audio = audioTracks.value.map((a) => a.file);
        data.audio_lang = audioTracks.value.map((a) => a.lang);
    }
    if (captionTracks.value.length) {
        data.captions = captionTracks.value.map((c) => c.file);
        data.captions_lang = captionTracks.value.map((c) => c.lang);
    }
    router.post('/videos/manage', data, {
        forceFormData: true,
        preserveScroll: true,
        onStart: () => { busy.value = true; error.value = ''; notice.value = ''; progress.value = 0; },
        onProgress: (event) => { progress.value = event && event.percentage ? Math.round(event.percentage) : 0; },
        onFinish: () => { busy.value = false; progress.value = 0; },
        onError: (errors) => { error.value = Object.values(errors)[0] || t('c_front.video_manager.error_generic', 'The upload could not be saved. Please retry.'); },
        onSuccess: () => {
            notice.value = t('c_front.video_manager.notice_saved', 'Film saved to the library.');
            form.title = ''; form.subject = ''; form.summary = ''; form.replaceMaster = false;
            master.value = null; audioTracks.value = []; captionTracks.value = []; chosenSurfaces.value = [];
        },
    });
}

// ── assign editor (one film at a time) ───────────────────────────────────────
const assigningId = ref(null);
const assignSurfaces = ref([]);
const assignQuery = ref('');
const assignBusy = ref(false);
const assignError = ref('');

function openAssign(video) {
    assigningId.value = video.id;
    assignSurfaces.value = [...(video.assigned || [])];
    assignQuery.value = '';
    assignError.value = '';
}
function cancelAssign() {
    assigningId.value = null;
}
const assignFiltered = computed(() => {
    const q = assignQuery.value.trim().toLowerCase();
    if (!q) return props.surfaces;
    return props.surfaces.filter((s) => s.id.toLowerCase().includes(q) || String(s.title).toLowerCase().includes(q));
});

function saveAssign(videoId, clear) {
    if (assignBusy.value) return;
    router.post('/videos/manage/assign', {
        video_id: videoId,
        surfaces: clear ? [] : assignSurfaces.value,
        clear: clear ? 1 : 0,
    }, {
        preserveScroll: true,
        onStart: () => { assignBusy.value = true; assignError.value = ''; },
        onFinish: () => { assignBusy.value = false; },
        onError: (errors) => { assignError.value = Object.values(errors)[0] || t('c_front.video_manager.error_generic', 'The upload could not be saved. Please retry.'); },
        onSuccess: () => { assigningId.value = null; notice.value = t('c_front.video_manager.notice_assigned', 'Assignments saved.'); },
    });
}
</script>

<template>
    <PageScaffold :surface="surface" :title="t('c_front.video_manager.page_title', 'Manage videos')">
        <template #intro>{{ t('c_front.video_manager.intro', 'Upload a film\'s silent master and its audio and caption tracks, then assign films to surfaces for the Learning Drawer.') }}</template>

        <p v-if="!can.manage" role="status" class="preview">
            {{ t('c_front.video_manager.preview', 'Read-only preview. Uploading and assigning videos is an operator tool during development.') }}
        </p>
        <p v-else class="gloss">
            {{ t('c_front.video_manager.demo_note', 'Uploads write real files to the library. There is no demo mode here; the gate is the real operator role.') }}
        </p>

        <p v-if="notice" role="status" class="notice">{{ notice }}</p>

        <!-- Upload form (operator only) -->
        <form v-if="can.manage" class="vm-form" :aria-busy="busy" @submit.prevent="submit">
            <h2>{{ t('c_front.video_manager.upload_h', 'Upload a film') }}</h2>
            <p class="gloss">{{ t('c_front.video_manager.library_root', { path: libraryRoot }) }}</p>

            <label for="vm-title">{{ t('c_front.video_manager.title_label', 'Title') }}</label>
            <input id="vm-title" v-model="form.title" maxlength="160" required />

            <label for="vm-subject">{{ t('c_front.video_manager.subject_label', 'Subject folder (optional)') }}</label>
            <input id="vm-subject" v-model="form.subject" maxlength="160" />
            <p id="vm-subject-hint" class="gloss">{{ t('c_front.video_manager.subject_hint', 'Defaults to the title. Letters, digits, spaces and hyphens only. This is the on-disk folder name.') }}</p>

            <label for="vm-summary">{{ t('c_front.video_manager.summary_label', 'Summary (optional)') }}</label>
            <textarea id="vm-summary" v-model="form.summary" maxlength="2000" rows="2"></textarea>

            <label for="vm-master">{{ t('c_front.video_manager.master_label', 'Master film (.mp4, silent)') }}</label>
            <input id="vm-master" type="file" accept="video/mp4" @change="onMaster" />
            <p class="gloss">{{ t('c_front.video_manager.master_hint', 'Required when this subject has no master yet.') }}</p>

            <label class="vm-check">
                <input v-model="form.replaceMaster" type="checkbox" />
                {{ t('c_front.video_manager.replace_master_label', 'Replace the existing master') }}
            </label>

            <label for="vm-audio">{{ t('c_front.video_manager.audio_label', 'Audio tracks (.m4a)') }}</label>
            <input id="vm-audio" type="file" accept=".m4a,audio/mp4" multiple @change="onAudio" />
            <p class="gloss">{{ t('c_front.video_manager.audio_hint', 'One file per language. The language is read from the filename, or set it below.') }}</p>
            <ul v-if="audioTracks.length" class="vm-tracklist">
                <li v-for="(track, i) in audioTracks" :key="'a' + i" class="vm-trackrow">
                    <span class="vm-fname">{{ track.name }}</span>
                    <select v-model="track.lang" :aria-label="t('c_front.video_manager.file_language_label', { file: track.name })">
                        <option value="">{{ t('c_front.video_manager.select_language', 'Select a language') }}</option>
                        <option v-for="l in languages" :key="l.code" :value="l.code">{{ l.name }} ({{ l.code }})</option>
                    </select>
                </li>
            </ul>

            <label for="vm-captions">{{ t('c_front.video_manager.captions_label', 'Caption tracks (.vtt)') }}</label>
            <input id="vm-captions" type="file" accept=".vtt,text/vtt" multiple @change="onCaptions" />
            <p class="gloss">{{ t('c_front.video_manager.captions_hint', 'One file per language. The language is read from the filename, or set it below.') }}</p>
            <ul v-if="captionTracks.length" class="vm-tracklist">
                <li v-for="(track, i) in captionTracks" :key="'c' + i" class="vm-trackrow">
                    <span class="vm-fname">{{ track.name }}</span>
                    <select v-model="track.lang" :aria-label="t('c_front.video_manager.file_language_label', { file: track.name })">
                        <option value="">{{ t('c_front.video_manager.select_language', 'Select a language') }}</option>
                        <option v-for="l in languages" :key="l.code" :value="l.code">{{ l.name }} ({{ l.code }})</option>
                    </select>
                </li>
            </ul>

            <fieldset class="vm-surfaces">
                <legend>{{ t('c_front.video_manager.surfaces_label', 'Assign to surfaces (optional)') }}</legend>
                <label for="vm-surface-search">{{ t('c_front.video_manager.surfaces_search_label', 'Search surfaces') }}</label>
                <input id="vm-surface-search" v-model="surfaceQuery" type="search" :placeholder="t('c_front.video_manager.surfaces_search_placeholder', 'Filter by name or id')" />
                <div class="vm-checklist">
                    <label v-for="s in filteredSurfaces" :key="s.id" class="vm-check">
                        <input v-model="chosenSurfaces" type="checkbox" :value="s.id" />
                        {{ s.title }} <code>{{ s.id }}</code>
                    </label>
                    <p v-if="!filteredSurfaces.length" class="gloss">{{ t('c_front.video_manager.no_surfaces_match', 'No surfaces match your search.') }}</p>
                </div>
            </fieldset>

            <button type="submit" :disabled="!canSubmit">{{ t('c_front.video_manager.submit', 'Upload film') }}</button>

            <div v-if="busy" class="vm-progress">
                <p role="status">{{ t('c_front.video_manager.uploading', 'Uploading film…') }}</p>
                <div role="progressbar" :aria-valuenow="progress" aria-valuemin="0" aria-valuemax="100" :aria-label="t('c_front.video_manager.upload_progress', 'Upload progress')" class="vm-bar">
                    <span class="vm-bar-fill" :style="{ inlineSize: progress + '%' }"></span>
                </div>
                <p class="gloss">{{ t('c_front.video_manager.progress_line', { done: progress }) }}</p>
            </div>
            <p v-if="error" role="alert">{{ error }}</p>
        </form>

        <!-- Film list (everyone) -->
        <section class="vm-films" aria-labelledby="vm-films-h">
            <h2 id="vm-films-h">{{ t('c_front.video_manager.films_h', 'Films in the library') }}</h2>
            <p v-if="!videos.length" class="gloss">{{ t('c_front.video_manager.no_films', 'No films in the library yet.') }}</p>

            <article v-for="v in videos" :key="v.id" class="vm-film">
                <h3>
                    <span class="vm-film-title">{{ v.title_key ? t(v.title_key, v.title) : v.title }}</span>
                    <span class="vm-badge">{{ v.source === 'upload' ? t('c_front.video_manager.source_upload', 'Upload') : t('c_front.video_manager.source_registry', 'Registry') }}</span>
                </h3>
                <p class="meta">
                    <code>{{ v.id }}</code>
                    · {{ t('c_front.video_manager.tracks_line', { audio: v.audio, captions: v.captions }) }}
                    · <span :class="v.available ? 'vm-ok' : 'vm-warn'">{{ v.available ? t('c_front.video_manager.available_yes', 'On this server') : t('c_front.video_manager.available_no', 'Not on this server yet') }}</span>
                </p>
                <p class="meta">
                    <strong>{{ t('c_front.video_manager.assigned_h', 'Assigned surfaces') }}:</strong>
                    <template v-if="v.assigned && v.assigned.length">
                        <span v-for="sid in v.assigned" :key="sid" class="vm-assigned">{{ surfaceTitle[sid] || sid }}</span>
                    </template>
                    <span v-else class="gloss">{{ t('c_front.video_manager.assigned_none', 'Not assigned to any surface.') }}</span>
                </p>

                <button v-if="can.manage && assigningId !== v.id" type="button" class="vm-assign-btn" @click="openAssign(v)">
                    {{ t('c_front.video_manager.assign_button', 'Assign to surfaces') }}
                </button>

                <div v-if="can.manage && assigningId === v.id" class="vm-assign-editor" :aria-busy="assignBusy">
                    <h4>{{ t('c_front.video_manager.assign_h', { title: v.title_key ? t(v.title_key, v.title) : v.title }) }}</h4>
                    <label :for="'vm-assign-search-' + v.id">{{ t('c_front.video_manager.surfaces_search_label', 'Search surfaces') }}</label>
                    <input :id="'vm-assign-search-' + v.id" v-model="assignQuery" type="search" :placeholder="t('c_front.video_manager.surfaces_search_placeholder', 'Filter by name or id')" />
                    <div class="vm-checklist">
                        <label v-for="s in assignFiltered" :key="s.id" class="vm-check">
                            <input v-model="assignSurfaces" type="checkbox" :value="s.id" />
                            {{ s.title }} <code>{{ s.id }}</code>
                        </label>
                        <p v-if="!assignFiltered.length" class="gloss">{{ t('c_front.video_manager.no_surfaces_match', 'No surfaces match your search.') }}</p>
                    </div>
                    <div class="vm-assign-actions">
                        <button type="button" :disabled="assignBusy" @click="saveAssign(v.id, false)">{{ t('c_front.video_manager.assign_save', 'Save assignments') }}</button>
                        <button type="button" :disabled="assignBusy" @click="saveAssign(v.id, true)">{{ t('c_front.video_manager.assign_clear', 'Clear all assignments') }}</button>
                        <button type="button" :disabled="assignBusy" @click="cancelAssign">{{ t('c_front.video_manager.assign_cancel', 'Cancel') }}</button>
                    </div>
                    <p v-if="assignBusy" role="status">{{ t('c_front.video_manager.assigning', 'Saving assignments…') }}</p>
                    <p v-if="assignError" role="alert">{{ assignError }}</p>
                </div>
            </article>
        </section>
    </PageScaffold>
</template>

<style scoped>
.vm-form { display: grid; gap: .55rem; max-inline-size: 46rem; }
.preview, .notice { border: 1px solid var(--gov-border, #344054); padding: .75rem; border-radius: .5rem; }
button, input, select, textarea { min-block-size: 44px; font: inherit; }
input, select, textarea { inline-size: 100%; max-inline-size: 46rem; box-sizing: border-box; }
textarea { min-block-size: 3rem; }
button { inline-size: fit-content; cursor: pointer; }
.vm-check { display: flex; gap: .5rem; align-items: center; min-block-size: 44px; }
.vm-check input { inline-size: auto; min-block-size: auto; }
.vm-surfaces { border: 1px solid var(--gov-border, #344054); border-radius: .5rem; padding: .65rem; min-inline-size: 0; }
.vm-checklist { display: grid; gap: .15rem; max-block-size: 16rem; overflow: auto; margin-block-start: .35rem; }
.vm-tracklist { list-style: none; padding: 0; display: grid; gap: .35rem; }
.vm-trackrow { display: flex; flex-wrap: wrap; gap: .35rem; align-items: center; }
.vm-fname { overflow-wrap: anywhere; min-inline-size: 0; }
.vm-trackrow select { inline-size: auto; max-inline-size: 100%; }
.vm-progress { display: grid; gap: .25rem; }
.vm-bar { position: relative; block-size: .6rem; background: var(--gov-surface, #1f2937); border-radius: .3rem; overflow: hidden; }
.vm-bar-fill { display: block; block-size: 100%; background: var(--cc-gold-300, #d4a72c); }
.vm-films { margin-block-start: 1rem; }
.vm-film { border-block-start: 1px solid var(--gov-border, #344054); padding-block: .65rem; }
.vm-film h3 { display: flex; flex-wrap: wrap; gap: .25rem .75rem; align-items: baseline; max-inline-size: 100%; }
.vm-film-title { min-inline-size: 0; overflow-wrap: anywhere; }
.vm-badge { font-size: .75rem; text-transform: uppercase; border: 1px solid var(--gov-border, #344054); border-radius: .25rem; padding-inline: .35rem; }
.meta { font-size: .9rem; display: flex; flex-wrap: wrap; gap: .35rem; align-items: baseline; }
.vm-assigned { border: 1px solid var(--gov-border, #344054); border-radius: .25rem; padding-inline: .35rem; font-size: .8rem; }
.vm-ok { color: var(--gov-success, #16a34a); }
.vm-warn { color: var(--gov-fg-subtle, #94a3b8); }
.vm-assign-editor { border: 1px solid var(--gov-border, #344054); border-radius: .5rem; padding: .65rem; margin-block-start: .5rem; }
.vm-assign-actions { display: flex; flex-wrap: wrap; gap: .5rem; margin-block-start: .5rem; }
.gloss { opacity: .8; }
</style>
