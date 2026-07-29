<script setup>
/**
 * ShellV2/DemoFlyout — the third dock flyout: Demo mode's one home.
 *
 * THE CHASSIS (V3 synthesis S3, lane 6). The operator's shell semantics,
 * 2026-07-28: Demo mode and Dev mode are the same thing, chosen at setup;
 * when the world runs in that mode this flyout is active and lets a person
 * step through the entire process — assume any role or resident of a place,
 * control the clocks, everything needed for pre-play testing. Those controls
 * were scattered across a dev bar and bare POST endpoints; this panel is
 * where they all live now.
 *
 * WHAT LIVES HERE TODAY (moved from the retired DevBar strip):
 *   - the persona switcher (become anyone; return to yourself),
 *   - the residency-tool pointer,
 *   - the derived-roles line,
 *   - the RTL-flip + pseudo-locale QA toggles (S7 — v1 had them, V2 lost
 *     them).
 *
 * LANE 4 MOUNTS THE REST — clock controls (advance-N-days with the dry-run
 * plan rendered first), the chamber-cast console, assume-a-role-of-a-place,
 * scenario presets. Marked region below. This file is deliberately a flat
 * stack of .dev-control rows so new occupants compose without layout work.
 *
 * Server truth stays the boundary: every control in here talks to /dev/*
 * endpoints that only register in sandbox/local — this panel renders only
 * when the shell says the world is in demo mode, but the 404s are the gate.
 */
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { LOCALES } from '@/i18n/index.js';
import DevPersonaSwitcher from '@/Components/Shell/DevPersonaSwitcher.vue';

const props = defineProps({
    /** { name } of the impersonated user, or null. */
    impersonating: { type: Object, default: null },
    /** { name } of the real (impersonating) user. */
    realUser: { type: Object, default: null },
    /** Derived roles of the current session (R-xx ids). */
    roles: { type: Array, default: () => [] },
});

const { t, locale } = useI18n({ useScope: 'global' });

/* ------------------------------------------------ RTL / pseudo QA toggles
   Ported from the v1 shell (AppShell.vue dev-bar slot). RTL flip forces
   dir=rtl for layout QA without switching language; unchecking restores the
   locale's own direction. Pseudo-locale (en-XA) pads and accents every
   t()-routed string — S9 extends it to server-data text via the DOM walk. */
const rtlFlipped = ref(
    typeof document !== 'undefined' && document.documentElement.dir === 'rtl',
);
function onRtlFlip(event) {
    if (typeof document === 'undefined') return;
    rtlFlipped.value = event.target.checked;
    if (event.target.checked) {
        document.documentElement.dir = 'rtl';
    } else {
        const meta = LOCALES.find((l) => l.code === locale.value);
        document.documentElement.dir = meta?.dir ?? 'ltr';
    }
}
const pseudoOn = computed(() => locale.value === 'en-XA');
function onPseudoToggle(event) {
    locale.value = event.target.checked ? 'en-XA' : 'en';
}
</script>

<template>
    <div class="demo-flyout">
        <p class="cmdbar-panel-title eyebrow">
            Demo controls — not part of the application
            <span v-if="impersonating">
                · Impersonating {{ impersonating.name
                }}<template v-if="realUser"> (really {{ realUser.name }})</template>
            </span>
        </p>

        <DevPersonaSwitcher :impersonating="impersonating" />

        <a class="dev-control" href="/civic/residency">Residency tool → /civic/residency</a>

        <span v-if="roles.length" class="dev-control">
            Roles (derived): <span class="citation">{{ roles.join(' · ') }}</span>
        </span>

        <!-- ================= lane 4 mounts the demo semantics here =================
             Clock controls (advance-N-days, dry-run plan rendered before apply),
             the chamber-cast console, assume-a-resident-of-a-place, scenario
             presets (V3_SYNTHESIS_PLAN §3 D1–D7). Keep each occupant a
             .dev-control row (or a block that opens with one). -->

        <span class="demo-sep" aria-hidden="true">·</span>
        <label class="dev-control">
            <input type="checkbox" :checked="rtlFlipped" @change="onRtlFlip" />
            {{ t('demo.rtl') }}
        </label>
        <label class="dev-control">
            <input type="checkbox" :checked="pseudoOn" @change="onPseudoToggle" />
            {{ t('demo.pseudo') }}
        </label>
    </div>
</template>

<style scoped>
/* The panel is a flat stack; .cmdbar-panel--demo (components-v2.css) handles
   the flex-wrap row layout. Only the pieces the chassis owns are styled here. */
.demo-flyout {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--space-2) var(--space-3);
}
.demo-flyout > .persona {
    flex: 1 1 100%;
}
</style>
