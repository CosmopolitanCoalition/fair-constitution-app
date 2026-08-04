<script setup>
/**
 * ShellV2/CmdBar — the harmonized floating command bar (ported from
 * mockups/v3 shell-v2.js renderCommandBar). Fixed to the bottom; carries the
 * three flyouts — Menu (the two-tier player nav), Learn (the drawer), and
 * Demo (demo mode's one home; renders only when the world runs in
 * sandbox/demo mode — V3 synthesis S3). The guided-tour controls ride the
 * floating HEADER (TourBar), not here, so they are never squished alongside
 * the flyouts.
 *
 * Behavior contract (wireEvents port): one flyout open at a time; Escape
 * closes and refocuses the summary; clicking outside closes.
 */
import { onBeforeUnmount, onMounted, ref } from 'vue';
import Icon from '@/Components/Ui/Icon.vue';
import MenuNav from '@/Components/ShellV2/MenuNav.vue';
import LearnFlyout from '@/Components/ShellV2/LearnFlyout.vue';
import DemoFlyout from '@/Components/ShellV2/DemoFlyout.vue';

defineProps({
    roles: { type: Array, default: () => ['R-00'] },
    currentNavId: { type: String, default: null },
    /** Demo/Dev mode is ON for this world (instance.sandbox — or an
     *  impersonation is already active, so the way back stays reachable). */
    demo: { type: Boolean, default: false },
    /** { name } of the impersonated user, or null. */
    impersonating: { type: Object, default: null },
    /** { name } of the real (impersonating) user. */
    realUser: { type: Object, default: null },
    /** Setup still in progress — MenuNav locks what cannot work yet. */
    setupIncomplete: { type: Boolean, default: false },
});

const rootEl = ref(null);

function flies() {
    return rootEl.value ? Array.from(rootEl.value.querySelectorAll('.cmdbar-fly')) : [];
}
function onToggle(ev) {
    const t = ev.target;
    if (!t.classList?.contains('cmdbar-fly') || !t.open) return;
    for (const d of flies()) if (d !== t && d.open) d.removeAttribute('open');
}
function onKeydown(ev) {
    if (ev.key !== 'Escape') return;
    for (const d of flies()) {
        if (!d.open) continue;
        d.removeAttribute('open');
        d.querySelector('summary')?.focus();
    }
}
function onDocClick(ev) {
    for (const d of flies()) {
        if (d.open && !d.contains(ev.target)) d.removeAttribute('open');
    }
}
onMounted(() => {
    document.addEventListener('keydown', onKeydown);
    document.addEventListener('click', onDocClick);
});
onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKeydown);
    document.removeEventListener('click', onDocClick);
});
</script>

<template>
    <div ref="rootEl" class="cmdbar" aria-label="Navigation and learn" @toggle.capture="onToggle">
        <div class="cmdbar-flies">
            <details class="cmdbar-fly" id="cmd-menu">
                <summary class="cmdbar-btn">
                    <Icon name="menu" size="sm" /><span class="cmdbar-lbl">Menu</span>
                    <Icon name="chevron-down" size="sm" class="cmdbar-caret" />
                </summary>
                <div class="cmdbar-panel cmdbar-panel--menu">
                    <MenuNav :roles="roles" :current-nav-id="currentNavId" :sandbox="demo"
                             :setup-incomplete="setupIncomplete" />
                </div>
            </details>

            <details class="cmdbar-fly" id="cmd-learn">
                <summary class="cmdbar-btn">
                    <Icon name="graduation-cap" size="sm" /><span class="cmdbar-lbl">Learn</span>
                    <Icon name="chevron-down" size="sm" class="cmdbar-caret" />
                </summary>
                <div class="cmdbar-panel cmdbar-panel--learn">
                    <LearnFlyout />
                </div>
            </details>

            <details v-if="demo" class="cmdbar-fly" id="cmd-demo">
                <summary class="cmdbar-btn" :title="impersonating ? `Impersonating ${impersonating.name}` : undefined">
                    <Icon name="sliders" size="sm" /><span class="cmdbar-lbl">Demo<template v-if="impersonating"> · as {{ impersonating.name }}</template></span>
                    <Icon name="chevron-down" size="sm" class="cmdbar-caret" />
                </summary>
                <div class="cmdbar-panel cmdbar-panel--demo">
                    <DemoFlyout :impersonating="impersonating" :real-user="realUser" :roles="roles" />
                </div>
            </details>
        </div>
    </div>
</template>
