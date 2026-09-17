import './bootstrap';
import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import NavigationProgress from '@/Components/Shell/NavigationProgress.vue';
import { i18n, loadLocale } from '@/i18n/index.js';

/* The catalogs are fetched, not bundled (see i18n/index.js). Load English (the
   fallback) and the page's locale BEFORE the first paint so a French reader
   never sees a flash of English or of raw keys; a failed fetch is logged and
   the app mounts anyway. The initial locale is read from the Inertia root
   element, the same data-page createInertiaApp reads. */
function initialLocaleFromPage() {
    try {
        const page = JSON.parse(document.getElementById('app')?.dataset?.page ?? '{}');
        return page?.props?.locale || null;
    } catch {
        return null;
    }
}

const bootLocale = initialLocaleFromPage();
await Promise.all([loadLocale('en'), bootLocale && bootLocale !== 'en' ? loadLocale(bootLocale) : null]);

createInertiaApp({
    // One accessible indicator handles both Inertia and ordinary page links.
    progress: false,
    resolve: async (name) => {
        const page = await resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue'));
        /* AppShellV2 — the v3 shell — is the DEFAULT persistent layout
           (V3_SYNTHESIS_PLAN §2 S1; the mockups are the spec, operator ruling
           2026-07-28). Pages may override (layout wrapper function or an
           explicit v1 AppShell pin — the KEEP-class dev kits and the legacy
           operations console do) or opt out entirely with
           `defineOptions({ layout: null })` — auth and setup pages do. A
           presence check (not `??=`) so an explicit `layout: null` keeps the
           page bare. */
        if (!('layout' in page.default)) {
            page.default.layout = AppShellV2;
        }
        return page;
    },
    setup({ el, App, props, plugin }) {
        /* Chrome-only i18n (§C6): locale follows the shared `locale` prop on
           first paint; the header locale select switches it client-side. */
        const initialLocale = props.initialPage?.props?.locale;
        if (initialLocale && i18n.global.availableLocales.includes(initialLocale)) {
            i18n.global.locale.value = initialLocale;
        }

        createApp({ render: () => [h(NavigationProgress), h(App, props)] })
            .use(plugin)
            .use(i18n)
            .mount(el);

        document.getElementById('initial-page-loading')?.remove();
    },
}).catch((error) => {
    const notice = document.getElementById('initial-page-loading');
    if (notice) {
        notice.textContent = i18n.global.t('c_loading.start_failed');
        const retry = document.createElement('a');
        retry.href = window.location.href;
        retry.textContent = i18n.global.t('c_loading.refresh');
        notice.append(retry);
        notice.setAttribute('role', 'alert');
    }
    console.error('Unable to open the application', error);
});
