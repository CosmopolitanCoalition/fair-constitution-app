<script setup>
/**
 * Layouts/AppShellV2 — the v3 player chrome (Phase 1 of
 * docs/plans/mockups-v3-wiring/MASTER_PLAN.md), ported from mockups/v3
 * shell-v2.js. Pages OPT IN via `defineOptions({ layout: AppShellV2 })`
 * while the restyle wave runs; AppShell (v1) remains the default and the
 * KEEP-class surfaces never leave it this campaign.
 *
 * Anatomy (all styling from cga/components-v2.css, scoped .app-shell--v2):
 *   • floating header — the chrome row (wordmark → Home · jurisdiction ·
 *     locale · auth) plus the guided-tour strip beneath it when the tour
 *     mode is armed; slides away on scroll-down, returns on scroll-up;
 *   • main — same default/wide/flush variant contract as AppShell;
 *   • footer — reused AppFooter (citation · instance · audit seq);
 *   • bottom command bar — Menu (two-tier player nav) + Learn flyouts.
 *
 * The tour is a MODE (useTour): session-persistent, follows navigation,
 * Exit ends it — operator-settled semantics, verified in the mockups.
 */
import { computed, onBeforeUnmount, onMounted, provide, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppFooter from '@/Components/Shell/AppFooter.vue';
import DevBar from '@/Components/Shell/DevBar.vue';
import EmergencyBanner from '@/Components/Shell/EmergencyBanner.vue';
import JurisdictionSwitcher from '@/Components/Shell/JurisdictionSwitcher.vue';
import SchemaUpdateBanner from '@/Components/SchemaUpdateBanner.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Icon from '@/Components/Ui/Icon.vue';
import CmdBar from '@/Components/ShellV2/CmdBar.vue';
import TourBar from '@/Components/ShellV2/TourBar.vue';
import { PLAYER_NAV, SITEMAP } from '@/registry/surfaces.js';
import { LOCALES } from '@/i18n/index.js';

const props = defineProps({
    /** Main width contract: 'default' (56rem) | 'wide' (96rem) | 'flush'. */
    variant: {
        type: String,
        default: 'default',
        validator: (v) => ['default', 'wide', 'flush'].includes(v),
    },
});

const page = usePage();
const { t, locale } = useI18n({ useScope: 'global' });

/* ---------------------------------------------- shared props (defensive) */
const auth = computed(() => page.props.auth ?? {});
const user = computed(() => auth.value.user ?? null);
const roles = computed(() => auth.value.roles ?? ['R-00']);
const instance = computed(() => page.props.instance ?? {});
const jurisdiction = computed(() => page.props.jurisdiction ?? null);
// Shared by HandleInertiaRequests as auth.impersonating (not a top-level prop).
const impersonation = computed(() => auth.value.impersonating ?? null);
const surface = computed(() => page.props.surface ?? null);
const activeEmergencies = computed(() => page.props.app?.activeEmergencies ?? []);

provide('cga:surface', surface);

const continueHref = computed(() => '/continue?to=' + encodeURIComponent(page.url ?? '/'));

const mainClass = computed(() => ({
    'main--wide': props.variant === 'wide',
    'main--flush': props.variant === 'flush',
}));

/* --------------------------------------------------------------- identity */
const appName = computed(() => instance.value.name || t('app.name'));

const initials = computed(() => {
    const name = user.value?.display_name || user.value?.name || '';
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((w) => w[0].toUpperCase())
        .join('');
});

/* ------------------------------------------------------------- navigation */
const currentNavId = computed(() => {
    if (surface.value?.nav) return surface.value.nav;
    const path = (page.url ?? '/').split('?')[0];
    let best = null;
    let bestLen = -1;
    const scan = (items) => {
        for (const item of items) {
            if (!item.href || item.href.startsWith('tour:')) continue;
            const href = item.href.split('?')[0];
            const match = href === '/' ? path === '/' : path === href || path.startsWith(`${href}/`);
            if (match && href.length > bestLen) {
                best = item.id;
                bestLen = href.length;
            }
        }
    };
    scan(PLAYER_NAV);
    for (const section of SITEMAP) scan(section.items);
    return best;
});

function onSwitchJurisdiction(id) {
    const target = (jurisdiction.value?.chain ?? []).find((j) => j.id === id);
    if (target?.slug) router.visit(`/jurisdictions/${target.slug}`);
}

/* ----------------------------------------------------------------- footer */
const footerInstance = computed(() => ({
    host: instance.value.host ?? (typeof window !== 'undefined' ? window.location.host : ''),
    authoritativeFor: instance.value.authoritativeFor ?? instance.value.name ?? '—',
}));
const auditSeq = computed(() => {
    const n = instance.value.auditSeq;
    return typeof n === 'number' ? n : null;
});

/* ----------------------------------------------------------------- locale */
function applyDir(code) {
    if (typeof document === 'undefined') return;
    const meta = LOCALES.find((l) => l.code === code);
    document.documentElement.lang = code;
    document.documentElement.dir = meta?.dir ?? 'ltr';
}
function onLocaleChange(event) {
    locale.value = event.target.value;
}
watch(locale, (code) => applyDir(code));

/* ------------------------------------------------- floating-header scroll
   Port of initHeaderScroll: hide on scroll-down, show the moment the player
   scrolls up (and always near the top). */
let lastY = 0;
let headerEl = null;
function onScroll() {
    if (!headerEl) headerEl = document.querySelector('.app-shell--v2 > .app-header');
    if (!headerEl) return;
    const y = window.scrollY || 0;
    const h = headerEl.offsetHeight || 0;
    if (y <= h || y < lastY - 2) headerEl.classList.remove('app-header--hidden');
    else if (y > lastY + 2) headerEl.classList.add('app-header--hidden');
    lastY = y;
}

/* ---------------------------------------------------------------- dev bar */
// DevBar shows only when the WORLD is in sandbox game mode (or impersonation is
// already active / an explicit devBar prop) — not keyed on import.meta.env.DEV.
const devBarOn = computed(
    () => page.props.devBar === true || impersonation.value?.active === true || instance.value.sandbox === true,
);
const impersonatingUser = computed(() =>
    impersonation.value?.active ? (user.value ? { name: user.value.display_name || user.value.name } : null) : null,
);
const realUser = computed(() => impersonation.value?.realUser ?? null);

function logout() {
    router.post('/logout');
}

/* Header popovers (<details class="popover">): Escape closes + focus
   restore; outside click closes — same contract as AppShell. */
function onKeydown(ev) {
    if (ev.key !== 'Escape') return;
    document.querySelectorAll('details.popover[open]').forEach((d) => {
        d.removeAttribute('open');
        const sum = d.querySelector('summary');
        if (sum && (d.contains(document.activeElement) || document.activeElement === document.body)) {
            sum.focus();
        }
    });
}
function onDocClick(ev) {
    document.querySelectorAll('details.popover[open]').forEach((d) => {
        if (!d.contains(ev.target)) d.removeAttribute('open');
    });
}
onMounted(() => {
    document.addEventListener('keydown', onKeydown);
    document.addEventListener('click', onDocClick);
    window.addEventListener('scroll', onScroll, { passive: true });
    applyDir(locale.value);
    lastY = window.scrollY || 0;
});
onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKeydown);
    document.removeEventListener('click', onDocClick);
    window.removeEventListener('scroll', onScroll);
});
</script>

<template>
    <div class="app-shell app-shell--v2" :class="{ 'app-shell--flush': variant === 'flush' }">
        <a class="skip-link" href="#main">{{ t('app.skip') }}</a>

        <!-- Persistent polite live region (WCAG 4.1.3); written via useAnnounce(). -->
        <div id="cga-live" class="visually-hidden" role="status" aria-live="polite"></div>

        <header class="app-header">
            <div class="hdr-row">
                <a class="wordmark" href="/civic">
                    <span>{{ appName }}</span>
                </a>

                <JurisdictionSwitcher
                    v-if="jurisdiction?.current"
                    :current="jurisdiction.current"
                    :chain="jurisdiction.chain ?? [jurisdiction.current]"
                    :cosmic-prefix="jurisdiction.cosmicPrefix ?? ''"
                    @switch="onSwitchJurisdiction"
                />

                <span class="header-spacer"></span>

                <label style="display: inline-flex; align-items: center; gap: var(--space-1)">
                    <span class="visually-hidden">{{ t('header.language') }}</span>
                    <Icon name="languages" size="sm" />
                    <select
                        class="select"
                        style="inline-size: auto"
                        :value="locale"
                        @change="onLocaleChange"
                    >
                        <option v-for="l in LOCALES" :key="l.code" :value="l.code">{{ l.name }}</option>
                    </select>
                </label>

                <details v-if="user" class="popover">
                    <summary :title="t('header.persona')">
                        <span class="role-badge">
                            <span class="avatar" aria-hidden="true">{{ initials }}</span>
                            <span>{{ user.display_name || user.name }}</span>
                        </span>
                    </summary>
                    <div class="popover-panel">
                        <span class="eyebrow">{{ user.display_name || user.name }}</span>
                        <p class="citation" style="margin-block: var(--space-1) var(--space-2)">
                            {{ user.email }}
                        </p>
                        <Btn
                            variant="ghost"
                            size="sm"
                            style="inline-size: 100%; justify-content: flex-start"
                            icon="x"
                            @click="logout"
                        >
                            Log out
                        </Btn>
                    </div>
                </details>
                <span v-else class="cluster" style="gap: var(--space-2)">
                    <Btn as="a" href="/login" variant="ghost" size="sm">Log in</Btn>
                    <Btn as="a" href="/register" variant="primary" size="sm">Register</Btn>
                </span>
            </div>

            <!-- the guided-tour strip rides the floating header (tour-as-a-mode) -->
            <TourBar />
        </header>

        <main id="main" class="main-content" :class="mainClass">
            <div class="shell-banners" style="flex-shrink: 0">
                <SchemaUpdateBanner />
                <EmergencyBanner :emergencies="activeEmergencies" />
                <Banner v-if="!user && surface" tone="info" title="You’re viewing as a guest">
                    These proceedings are public record (Art. II §2).
                    <a :href="continueHref"><strong>Sign up to take part</strong></a> — speak, vote, and stand
                    for office once your residency is confirmed.
                </Banner>
            </div>

            <slot />
        </main>

        <AppFooter
            :citation="surface?.citation ?? null"
            :instance="footerInstance"
            :audit-seq="auditSeq"
        />

        <CmdBar :roles="roles" :current-nav-id="currentNavId" />

        <DevBar
            v-if="devBarOn"
            :impersonating="impersonatingUser"
            :real-user="realUser"
        />
    </div>
</template>
