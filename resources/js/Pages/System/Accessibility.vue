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
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Card from '@/Components/Ui/Card.vue';

defineOptions({ layout: AppShellV2 });

defineProps({
    surface: { type: Object, required: true },
});
</script>

<template>
    <PageScaffold :surface="surface">
        <template #intro>
            Representation for every one of us means an interface every one of us can operate —
            across ability, language, script direction, device, and connection. This statement
            covers the mockup site itself and records the accessibility contract the production
            build inherits.
        </template>

        <p class="citation">
            Target: WCAG 2.2 Level AA, with some Level AAA · EN 301 549 · last reviewed 2026-06-11
        </p>

        <!-- ─────────────────────────────────────── what is built in ── -->
        <Card as="section" title="What is built in">
            <ul class="stack" style="gap: var(--space-3)">
                <li>
                    <strong>Keyboard.</strong> Every interaction works without a pointer: a skip
                    link is the first focusable element on every page, header menus are native
                    disclosure widgets (Escape closes them), the ranked ballot uses move buttons
                    rather than drag-only ordering, and flow walkthroughs are plain buttons and
                    links.
                    <span class="citation">WCAG 2.1.1 · 2.1.2 · 2.4.1 · 2.5.7</span>
                </li>
                <li>
                    <strong>Visible focus.</strong> A 3-pixel gold ring marks focus on every
                    control; under Windows contrast themes the same outline renders in the system
                    highlight color. Sticky chrome reserves scroll padding so the focused element
                    is never hidden behind it.
                    <span class="citation">WCAG 2.4.7 · 2.4.11 · 2.4.13</span>
                </li>
                <li>
                    <strong>Contrast.</strong> Text and interface colors are token-pairs checked
                    against the AA ratios (4.5:1 text, 3:1 components); the quiet text tiers step
                    up automatically under <code data-no-i18n>prefers-contrast: more</code>. Color
                    never carries meaning alone — every status pairs a label or icon with its hue,
                    and the map layers use the Wong colorblind-safe palette.
                    <span class="citation">WCAG 1.4.1 · 1.4.3 · 1.4.11</span>
                </li>
                <li>
                    <strong>Reflow.</strong> Layouts hold from 320 CSS pixels (and at 400% zoom)
                    through phone, tablet, and large monitors, portrait or landscape, with no
                    horizontal scrolling and no orientation lock.
                    <span class="citation">WCAG 1.3.4 · 1.4.4 · 1.4.10</span>
                </li>
                <li>
                    <strong>Touch targets.</strong> Interactive elements are at least 24×24 CSS
                    pixels including the compact chip controls.
                    <span class="citation">WCAG 2.5.8</span>
                </li>
                <li>
                    <strong>Structure.</strong> Every page declares its language, carries a unique
                    title, one h1, ordered heading levels, and the full landmark set (banner,
                    navigation, main, contentinfo); status updates are announced through a
                    persistent polite live region.
                    <span class="citation">WCAG 1.3.1 · 2.4.2 · 3.1.1 · 4.1.3</span>
                </li>
                <li>
                    <strong>Motion.</strong> Animations are sub-300ms, nothing flashes, and
                    <code data-no-i18n>prefers-reduced-motion</code> disables all non-essential
                    transitions.
                    <span class="citation">WCAG 2.3.1 · 2.3.3</span>
                </li>
                <li>
                    <strong>No timeouts.</strong> Constitutional clocks on these screens are
                    simulated displays — nothing on the mockup site expires or auto-advances.
                    <span class="citation">WCAG 2.2.1 · 2.2.3</span>
                </li>
                <li>
                    <strong>Internationalization.</strong> All layout uses CSS logical properties
                    so right-to-left scripts mirror correctly (flip the direction from the Demo
                    controls to verify); the language switcher sits in the header on every page; a
                    pseudo-locale with 35% text expansion is part of the QA toolchain.
                    <span class="citation">WCAG 3.1.1 · 3.1.2 · UAX #9</span>
                </li>
                <li>
                    <strong>Offline-capable.</strong> Fonts are self-hosted (SIL OFL 1.1) and no
                    resource loads from any external service — the site runs fully on a LAN with no
                    internet access, on low-power hardware.
                </li>
            </ul>
        </Card>

        <!-- ─────────────────────────────────────── known limitations ── -->
        <Card as="section" title="Known limitations">
            <ul class="stack" style="gap: var(--space-2)">
                <li>
                    Page body copy ships in English; the locale switcher translates the application
                    chrome only. The production build externalizes every string through an ICU
                    MessageFormat catalog per the internationalization plan.
                </li>
                <li>
                    The stylized map panes (jurisdiction browser, district mapper) are placement
                    stand-ins for the developed Leaflet viewers; each carries a text description,
                    and the adjacent panel presents the same data as text. The production viewers
                    ship with full keyboard navigation and text equivalents.
                </li>
                <li>
                    Automated and structural checks run on every page of this site;
                    assistive-technology walkthroughs with human testers (screen reader, switch,
                    voice control) are scheduled against the production build, not these static
                    mockups.
                </li>
                <li>
                    The Demo controls at the foot of each screen are a demonstration instrument,
                    not part of the application contract.
                </li>
            </ul>
        </Card>

        <!-- ─────────────────────────────────────────────── feedback ── -->
        <Card as="section" title="Feedback">
            <p>
                If anything here is hard to perceive, operate, or understand, that is a defect in
                the design contract — report it to the
                <a href="https://www.cosmopolitancoalition.org" rel="external noopener">Cosmopolitan Coalition</a>
                so it is fixed before the production build inherits it. You can also
                <a href="/support/report?ref=shared/accessibility">file an accessibility report</a>
                from inside the app.
            </p>
            <p class="citation">
                Voting and candidacy require nothing beyond living in the place you are voting in —
                the interface must not add barriers the constitution forbids · Art. I
            </p>
        </Card>
    </PageScaffold>
</template>
