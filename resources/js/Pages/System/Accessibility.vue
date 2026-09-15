<script setup>
/**
 * System/Accessibility — the accessibility statement (design contract:
 * mockups/v3/shared/accessibility.html).
 *
 * Surface id `shared/accessibility` (module `shared`) — matches the mockup
 * CGA_PAGE id and the authored Learn copy in registry/education.js, so the
 * Learn drawer resolves. Routed at /system/accessibility (public read) — the
 * footer's Accessibility link and every `· as implemented` citation can
 * appear before sign-in, so this honest public document must be reachable
 * without a session.
 *
 * READ-ONLY. This records the accessibility contract the production build
 * inherits; the "Known limitations" section states, plainly, where the
 * static mockup site and the production build still differ.
 */
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Card from '@/Components/Ui/Card.vue';

defineOptions({ layout: AppShellV2 });

const { t } = useI18n();

defineProps({
    surface: { type: Object, required: true },
});
</script>

<template>
    <PageScaffold :surface="surface">
        <template #intro>
            {{ t('c_system.accessibility.intro', 'Representation for every one of us means an interface every one of us can operate — across ability, language, script direction, device, and connection. This statement covers the mockup site itself and records the accessibility contract the production build inherits.') }}
        </template>

        <p class="citation">
            {{ t('c_system.accessibility.review_line', 'Target: WCAG 2.2 Level AA, with some Level AAA · EN 301 549 · last reviewed 2026-06-11') }}
        </p>

        <!-- ─────────────────────────────────────── what is built in ── -->
        <Card as="section" :title="t('c_system.accessibility.builtin_title', 'What is built in')">
            <ul class="stack" style="gap: var(--space-3)">
                <li>
                    <strong>{{ t('c_system.accessibility.keyboard_label', 'Keyboard.') }}</strong> {{ t('c_system.accessibility.keyboard_body', 'Every interaction works without a pointer: a skip link is the first focusable element on every page, header menus are native disclosure widgets (Escape closes them), the ranked ballot uses move buttons rather than drag-only ordering, and flow walkthroughs are plain buttons and links.') }}
                    <span class="citation" data-no-i18n>WCAG 2.1.1 · 2.1.2 · 2.4.1 · 2.5.7</span>
                </li>
                <li>
                    <strong>{{ t('c_system.accessibility.focus_label', 'Visible focus.') }}</strong> {{ t('c_system.accessibility.focus_body', 'A 3-pixel gold ring marks focus on every control; under Windows contrast themes the same outline renders in the system highlight color. Sticky chrome reserves scroll padding so the focused element is never hidden behind it.') }}
                    <span class="citation" data-no-i18n>WCAG 2.4.7 · 2.4.11 · 2.4.13</span>
                </li>
                <li>
                    <strong>{{ t('c_system.accessibility.contrast_label', 'Contrast.') }}</strong> {{ t('c_system.accessibility.contrast_body_a', 'Text and interface colors are token-pairs checked against the AA ratios (4.5:1 text, 3:1 components); the quiet text tiers step up automatically under') }} <code data-no-i18n>prefers-contrast: more</code>{{ t('c_system.accessibility.contrast_body_b', '. Color never carries meaning alone — every status pairs a label or icon with its hue, and the map layers use the Wong colorblind-safe palette.') }}
                    <span class="citation" data-no-i18n>WCAG 1.4.1 · 1.4.3 · 1.4.11</span>
                </li>
                <li>
                    <strong>{{ t('c_system.accessibility.reflow_label', 'Reflow.') }}</strong> {{ t('c_system.accessibility.reflow_body', 'Layouts hold from 320 CSS pixels (and at 400% zoom) through phone, tablet, and large monitors, portrait or landscape, with no horizontal scrolling and no orientation lock.') }}
                    <span class="citation" data-no-i18n>WCAG 1.3.4 · 1.4.4 · 1.4.10</span>
                </li>
                <li>
                    <strong>{{ t('c_system.accessibility.touch_label', 'Touch targets.') }}</strong> {{ t('c_system.accessibility.touch_body', 'Interactive elements are at least 24×24 CSS pixels including the compact chip controls.') }}
                    <span class="citation" data-no-i18n>WCAG 2.5.8</span>
                </li>
                <li>
                    <strong>{{ t('c_system.accessibility.structure_label', 'Structure.') }}</strong> {{ t('c_system.accessibility.structure_body', 'Every page declares its language, carries a unique title, one h1, ordered heading levels, and the full landmark set (banner, navigation, main, contentinfo); status updates are announced through a persistent polite live region.') }}
                    <span class="citation" data-no-i18n>WCAG 1.3.1 · 2.4.2 · 3.1.1 · 4.1.3</span>
                </li>
                <li>
                    <strong>{{ t('c_system.accessibility.motion_label', 'Motion.') }}</strong> {{ t('c_system.accessibility.motion_body_a', 'Animations are sub-300ms, nothing flashes, and') }} <code data-no-i18n>prefers-reduced-motion</code> {{ t('c_system.accessibility.motion_body_b', 'disables all non-essential transitions.') }}
                    <span class="citation" data-no-i18n>WCAG 2.3.1 · 2.3.3</span>
                </li>
                <li>
                    <strong>{{ t('c_system.accessibility.timeouts_label', 'No timeouts.') }}</strong> {{ t('c_system.accessibility.timeouts_body', 'Constitutional clocks on these screens are simulated displays — nothing on the mockup site expires or auto-advances.') }}
                    <span class="citation" data-no-i18n>WCAG 2.2.1 · 2.2.3</span>
                </li>
                <li>
                    <strong>{{ t('c_system.accessibility.i18n_label', 'Internationalization.') }}</strong> {{ t('c_system.accessibility.i18n_body', 'All layout uses CSS logical properties so right-to-left scripts mirror correctly (flip the direction from the Demo controls to verify); the language switcher sits in the header on every page; a pseudo-locale with 35% text expansion is part of the QA toolchain.') }}
                    <span class="citation" data-no-i18n>WCAG 3.1.1 · 3.1.2 · UAX #9</span>
                </li>
                <li>
                    <strong>{{ t('c_system.accessibility.offline_label', 'Offline-capable.') }}</strong> {{ t('c_system.accessibility.offline_body', 'Fonts are self-hosted (SIL OFL 1.1) and no resource loads from any external service — the site runs fully on a LAN with no internet access, on low-power hardware.') }}
                </li>
            </ul>
        </Card>

        <!-- ─────────────────────────────────────── known limitations ── -->
        <Card as="section" :title="t('c_system.accessibility.limits_title', 'Known limitations')">
            <ul class="stack" style="gap: var(--space-2)">
                <li>
                    {{ t('c_system.accessibility.limit_copy', 'Page body copy ships in English; the locale switcher translates the application chrome only. The production build externalizes every string through an ICU MessageFormat catalog per the internationalization plan.') }}
                </li>
                <li>
                    {{ t('c_system.accessibility.limit_map', 'The stylized map panes (jurisdiction browser, district mapper) are placement stand-ins for the developed Leaflet viewers; each carries a text description, and the adjacent panel presents the same data as text. The production viewers ship with full keyboard navigation and text equivalents.') }}
                </li>
                <li>
                    {{ t('c_system.accessibility.limit_testing', 'Automated and structural checks run on every page of this site; assistive-technology walkthroughs with human testers (screen reader, switch, voice control) are scheduled against the production build, not these static mockups.') }}
                </li>
                <li>
                    {{ t('c_system.accessibility.limit_demo', 'The Demo controls at the foot of each screen are a demonstration instrument, not part of the application contract.') }}
                </li>
            </ul>
        </Card>

        <!-- ─────────────────────────────────────────────── feedback ── -->
        <Card as="section" :title="t('c_system.accessibility.feedback_title', 'Feedback')">
            <p>
                {{ t('c_system.accessibility.feedback_before', 'If anything here is hard to perceive, operate, or understand, that is a defect in the design contract — report it to the') }}
                <a href="https://www.cosmopolitancoalition.org" rel="external noopener">{{ t('c_system.accessibility.feedback_coalition', 'Cosmopolitan Coalition') }}</a>
                {{ t('c_system.accessibility.feedback_mid', 'so it is fixed before the production build inherits it. You can also') }}
                <a href="/support/report?ref=shared/accessibility">{{ t('c_system.accessibility.feedback_report', 'file an accessibility report') }}</a>
                {{ t('c_system.accessibility.feedback_after', 'from inside the app.') }}
            </p>
            <p class="citation">
                {{ t('c_system.accessibility.feedback_cite', 'Voting and candidacy require nothing beyond living in the place you are voting in — the interface must not add barriers the constitution forbids · Art. I') }}
            </p>
        </Card>
    </PageScaffold>
</template>
