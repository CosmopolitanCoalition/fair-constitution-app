// Browser review harness (L1/L2 · video). Mounts the REAL
// resources/js/Components/Media/MultiTrackVideoPlayer.vue through the running
// Vite dev server, so the component's own template, watchers, media event
// handlers and lifecycle run against a real HTMLMediaElement. The video prop,
// baseUrl and locale are handed in via window.__CGA_MOUNT__ (set by the test
// with addInitScript before this module runs). No test-only defineExpose:
// assertions read the real <video>/<audio> elements and the rendered DOM.
import { createApp } from 'vue';
import MultiTrackVideoPlayer from '@/Components/Media/MultiTrackVideoPlayer.vue';

const cfg = window.__CGA_MOUNT__ || {};
const app = createApp(MultiTrackVideoPlayer, {
    video: cfg.video,
    baseUrl: Object.prototype.hasOwnProperty.call(cfg, 'baseUrl') ? cfg.baseUrl : null,
    initialLocale: cfg.initialLocale || 'en',
});
app.mount('#app');
window.__CGA_MOUNTED__ = true;
