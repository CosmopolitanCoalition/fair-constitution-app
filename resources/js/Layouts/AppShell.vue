<script setup>
/**
 * Layouts/AppShell — the ported mockup chrome as the app's persistent Inertia
 * layout (DESIGN_frontend_port.md §C1). Registered as the DEFAULT layout in
 * app.js (`page.default.layout ??= AppShell`); pages opt out with
 * `defineOptions({ layout: null })` (auth) or override the variant/chrome via
 * a layout wrapper function.
 *
 * Grid: .app-shell → skip link · #cga-live · AppHeader · AppSidebar ·
 * main#main (banners region + page slot) · AppFooter · DevBar (dev only).
 *
 * Variants (main#main):
 *   default → constrained .main-content (56rem)
 *   wide    → .main--wide (96rem)
 *   flush   → .main--flush (no padding/max-width, flex column, overflow
 *             hidden — the Leaflet contract the map tools size against)
 *
 * Chrome modes:
 *   full    → header + sidebar + footer
 *   minimal → header + footer only (setup wizard). Also forced while the
 *             instance setup is incomplete (`instance.setupComplete`), which
 *             preserves the legacy AppLayout behavior of hiding nav pre-setup.
 *
 * Shared-prop consumption is DEFENSIVE: `jurisdiction`, `impersonation`,
 * `app.phasesLive` and `devBar` ship from a parallel work item — every read
 * has a null fallback so the shell renders before and after they land.
 */
import { computed, onBeforeUnmount, onMounted, provide, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppHeader from '@/Components/Shell/AppHeader.vue';
import AppSidebar from '@/Components/Shell/AppSidebar.vue';
import AppFooter from '@/Components/Shell/AppFooter.vue';
import DevBar from '@/Components/Shell/DevBar.vue';
import JurisdictionSwitcher from '@/Components/Shell/JurisdictionSwitcher.vue';
import SchemaUpdateBanner from '@/Components/SchemaUpdateBanner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Icon from '@/Components/Ui/Icon.vue';
import { NAV } from '@/Navigation/nav.js';
import { LOCALES } from '@/i18n/index.js';

const props = defineProps({
    /** Main width contract: 'default' (56rem) | 'wide' (96rem) | 'flush'. */
    variant: {
        type: String,
        default: 'default',
        validator: (v) => ['default', 'wide', 'flush'].includes(v),
    },
    /** 'full' (header+sidebar+footer) | 'minimal' (header+footer — setup). */
    chrome: {
        type: String,
        default: 'full',
        validator: (v) => ['full', 'minimal'].includes(v),
    },
});

const page = usePage();
const { t, locale } = useI18n({ useScope: 'global' });

/* ------------------------------------------------------- shared props (defensive) */
const auth = computed(() => page.props.auth ?? {});
const user = computed(() => auth.value.user ?? null);
const roles = computed(() => auth.value.roles ?? ['R-00']);
const instance = computed(() => page.props.instance ?? {});
const jurisdiction = computed(() => page.props.jurisdiction ?? null);
const impersonation = computed(() => page.props.impersonation ?? null);
const surface = computed(() => page.props.surface ?? null);
const phasesLive = computed(() => page.props.app?.phasesLive ?? ['A']);
const setupComplete = computed(() => instance.value.setupComplete ?? true);

/* Pages below (PageScaffold, AboutSurface consumers) can inject the surface
   without re-reading page props. */
provide('cga:surface', surface);

/* ------------------------------------------------------------------ chrome */
const minimal = computed(() => props.chrome === 'minimal' || !setupComplete.value);

/* The ported .app-shell grid reserves a 15rem sidebar column; in minimal
   chrome the sidebar is absent, so collapse to a single column (inline style
   — keeps cga/components.css byte-diffable against the mockups). */
const shellStyle = computed(() =>
    minimal.value
        ? {
              gridTemplateColumns: 'minmax(0, 1fr)',
              gridTemplateAreas: '"header" "main" "footer" "devbar"',
              gridTemplateRows: 'auto 1fr auto auto',
          }
        : undefined,
);

const mainClass = computed(() => ({
    'main--wide': props.variant === 'wide',
    'main--flush': props.variant === 'flush',
}));

/* --------------------------------------------------------------- identity */
const appName = computed(() => instance.value.name || t('app.name'));

const ROLE_LABELS = {
    'R-00': 'Visitor',
    'R-01': 'Individual',
    'R-02': 'Resident',
    'R-03': 'Jurisdictionally Associated',
    'R-04': 'Voter',
    'R-05': 'Petitioner',
};

const highestRole = computed(() => {
    const sorted = [...roles.value].sort(
        (a, b) => (parseInt(a.slice(2), 10) || 0) - (parseInt(b.slice(2), 10) || 0),
    );
    const id = sorted[sorted.length - 1] ?? 'R-00';
    return { id, label: ROLE_LABELS[id] ?? id };
});

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
    /* URL-prefix fallback: longest nav href that prefixes the current path. */
    const path = (page.url ?? '/').split('?')[0];
    let best = null;
    let bestLen = -1;
    for (const section of NAV) {
        for (const item of section.items) {
            const href = item.href;
            const match = href === '/' ? path === '/' : path === href || path.startsWith(`${href}/`);
            if (match && href.length > bestLen) {
                best = item.id;
                bestLen = href.length;
            }
        }
    }
    return best;
});

function onSwitchJurisdiction(id) {
    /* Phase A: navigate to the jurisdiction's viewer page. The session
       view-context endpoint ships with the dev-impersonation work item. */
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

/* ---------------------------------------------------------------- dev bar */
const devBarOn = computed(
    () => page.props.devBar === true || impersonation.value?.active === true || import.meta.env.DEV,
);
const impersonatingUser = computed(() =>
    impersonation.value?.active ? (user.value ? { name: user.value.display_name || user.value.name } : null) : null,
);
const realUser = computed(() => impersonation.value?.realUser ?? null);

const rtlFlipped = computed(
    () => typeof document !== 'undefined' && document.documentElement.dir === 'rtl',
);
function onRtlFlip(event) {
    if (typeof document === 'undefined') return;
    if (event.target.checked) {
        document.documentElement.dir = 'rtl';
    } else {
        applyDir(locale.value);
    }
}
const pseudoOn = computed(() => locale.value === 'en-XA');
function onPseudoToggle(event) {
    locale.value = event.target.checked ? 'en-XA' : 'en';
}

function logout() {
    router.post('/logout');
}

/* Header popovers (<details class="popover">): Escape closes + restores
   focus to the trigger; clicking outside closes — ported from shell.js. */
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
    applyDir(locale.value);
});
onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKeydown);
    document.removeEventListener('click', onDocClick);
});
</script>

<template>
    <div class="app-shell" :class="{ 'app-shell--flush': variant === 'flush' }" :style="shellStyle">
        <a class="skip-link" href="#main">{{ t('app.skip') }}</a>

        <!-- Persistent polite live region (WCAG 4.1.3); written via useAnnounce(). -->
        <div id="cga-live" class="visually-hidden" role="status" aria-live="polite"></div>

        <AppHeader :app-name="appName" home-href="/">
            <template #switcher>
                <JurisdictionSwitcher
                    v-if="jurisdiction?.current"
                    :current="jurisdiction.current"
                    :chain="jurisdiction.chain ?? [jurisdiction.current]"
                    :cosmic-prefix="jurisdiction.cosmicPrefix ?? ''"
                    @switch="onSwitchJurisdiction"
                />
            </template>

            <template #search>
                <form class="global-search" role="search" action="/jurisdictions" method="get">
                    <Icon name="search" size="sm" />
                    <input
                        type="search"
                        name="search"
                        :aria-label="`${t('header.search')} — ${t('nav.jurisdictions')}`"
                        :placeholder="t('header.search')"
                    />
                </form>
            </template>

            <template #actions>
                <details class="popover">
                    <summary :aria-label="t('header.notifications')">
                        <Icon name="bell" />
                    </summary>
                    <div class="popover-panel">
                        <span class="eyebrow">{{ t('header.notifications') }}</span>
                        <p>{{ t('common.notifications.empty') }}</p>
                    </div>
                </details>

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
                        <option v-if="pseudoOn" value="en-XA">Pseudo (en-XA)</option>
                    </select>
                </label>
            </template>

            <template #auth>
                <details v-if="user" class="popover">
                    <summary :title="t('header.persona')">
                        <span class="role-badge">
                            <span class="avatar" aria-hidden="true">{{ initials }}</span>
                            <span>{{ user.display_name || user.name }}</span>
                            <span class="citation">{{ highestRole.id }} · {{ highestRole.label }}</span>
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
            </template>
        </AppHeader>

        <AppSidebar
            v-if="!minimal"
            :nav="NAV"
            :roles="roles"
            :current-nav-id="currentNavId"
            :phases-live="phasesLive"
        />

        <main id="main" class="main-content" :class="mainClass">
            <!-- Banners region: system-required alerts render on every chrome
                 mode, above the page content (the constitutional banners —
                 emergency powers etc. — mount here in their shipping phase). -->
            <div class="shell-banners" style="flex-shrink: 0">
                <SchemaUpdateBanner />
            </div>

            <slot />
        </main>

        <AppFooter
            :citation="surface?.citation ?? null"
            :instance="footerInstance"
            :audit-seq="auditSeq"
        />

        <DevBar
            v-if="devBarOn"
            :impersonating="impersonatingUser"
            :real-user="realUser"
        >
            <span class="dev-control">
                Roles (derived): <span class="citation">{{ roles.join(' · ') }}</span>
            </span>
            <label class="dev-control">
                <input type="checkbox" :checked="rtlFlipped" @change="onRtlFlip" />
                {{ t('demo.rtl') }}
            </label>
            <label class="dev-control">
                <input type="checkbox" :checked="pseudoOn" @change="onPseudoToggle" />
                {{ t('demo.pseudo') }}
            </label>
        </DevBar>
    </div>
</template>
