import './bootstrap';
import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import AppShell from '@/Layouts/AppShell.vue';
import { i18n } from '@/i18n/index.js';

createInertiaApp({
    resolve: async (name) => {
        const page = await resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue'));
        /* AppShell is the DEFAULT persistent layout (DESIGN_frontend_port.md
           §C1). Pages may override (layout wrapper function) or opt out with
           `defineOptions({ layout: null })` — auth pages do. A presence check
           (not `??=`) so an explicit `layout: null` keeps the page bare. */
        if (!('layout' in page.default)) {
            page.default.layout = AppShell;
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
