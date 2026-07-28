import './bootstrap';
import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import { i18n } from '@/i18n/index.js';

createInertiaApp({
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

        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(i18n)
            .mount(el);
    },
});
