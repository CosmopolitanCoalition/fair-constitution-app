<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { createNavigationProgress, isPageNavigation } from '@/lib/navigationProgress.js';

const { t } = useI18n();
const state = ref({ visible: false, slow: false, native: false, failed: false });
const progress = createNavigationProgress((value) => { state.value = value; });
const cleanup = [];

function linkClicked(event) {
    const link = event.target?.closest?.('a[href]');
    if (!link) return;
    if (isPageNavigation({
        href: link.href,
        target: link.target || document.querySelector('base[target]')?.target,
        download: link.hasAttribute('download'),
        prevented: event.defaultPrevented,
        modified: event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey,
        optOut: link.closest('[data-page-loading="false"]') || link.getAttribute('aria-disabled') === 'true',
    }, window.location.href)) progress.startDocument();
}

function formSubmitted(event) {
    const form = event.target;
    const button = event.submitter;
    if (form.method === 'dialog') return;
    if (isPageNavigation({
        href: button?.getAttribute('formaction') || form.action,
        target: button?.getAttribute('formtarget') || form.target || document.querySelector('base[target]')?.target,
        prevented: event.defaultPrevented,
        optOut: form.closest('[data-page-loading="false"]'),
    }, window.location.href)) progress.startDocument();
}

function escape(event) {
    if (event.key === 'Escape') progress.finishDocument();
}

onMounted(() => {
    cleanup.push(router.on('start', (event) => progress.startVisit(event.detail.visit)));
    cleanup.push(router.on('finish', (event) => progress.finishVisit(event.detail.visit)));
    cleanup.push(router.on('exception', (event) => progress.failRequest(event.detail.exception?.config)));
    // Observe native navigation after click handlers have had a chance to cancel it.
    // Inertia Links preventDefault and are covered by their request lifecycle above.
    window.addEventListener('click', linkClicked);
    window.addEventListener('submit', formSubmitted);
    window.addEventListener('pageshow', progress.reset);
    window.addEventListener('popstate', progress.finishDocument);
    window.addEventListener('keydown', escape);
});
onBeforeUnmount(() => {
    cleanup.forEach((stop) => stop());
    window.removeEventListener('click', linkClicked);
    window.removeEventListener('submit', formSubmitted);
    window.removeEventListener('pageshow', progress.reset);
    window.removeEventListener('popstate', progress.finishDocument);
    window.removeEventListener('keydown', escape);
    progress.reset();
});
</script>

<template>
    <div class="navigation-progress" :class="{ 'navigation-progress--visible': state.visible }">
        <span class="navigation-progress__announcement" role="status" aria-live="polite" aria-atomic="true">{{ state.visible ? t(state.failed ? 'c_loading.failed' : state.slow ? 'c_loading.slow' : 'c_loading.loading') : '' }}</span>
        <div v-if="state.visible && !state.failed" class="navigation-progress__track" aria-hidden="true"><span /></div>
        <div class="navigation-progress__message">
            <span aria-hidden="true">{{ state.visible ? t(state.failed ? 'c_loading.failed' : state.slow ? 'c_loading.slow' : 'c_loading.loading') : '' }}</span>
            <button v-if="state.visible && (state.failed || (state.slow && state.native))" type="button"
                @click="state.failed ? progress.reset() : progress.finishDocument()">{{ t('c_loading.dismiss') }}</button>
        </div>
    </div>
</template>

<style scoped>
.navigation-progress { position: fixed; inset: 0 0 auto; z-index: 10000; pointer-events: none; }
.navigation-progress__announcement { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip-path: inset(50%); white-space: nowrap; border: 0; }
.navigation-progress__track { height: 3px; overflow: hidden; background: var(--gov-surface, #101827); }
.navigation-progress__track span { display: block; width: 35%; height: 100%; background: var(--gov-link, #f6c453); animation: navigation-loading 1.25s ease-in-out infinite; }
.navigation-progress__message { display: none; }
.navigation-progress--visible .navigation-progress__message { display: flex; align-items: center; gap: .75rem; position: absolute; inset-block-start: .5rem; inset-inline-end: .75rem; max-inline-size: calc(100vw - 1.5rem); padding: .35rem .65rem; border: 1px solid var(--gov-border, #334155); border-radius: .4rem; background: var(--gov-surface, #101827); color: var(--gov-fg-strong, #fff); font-size: .8rem; box-shadow: 0 2px 8px #0004; }
.navigation-progress__message button { pointer-events: auto; color: var(--gov-link, #f6c453); background: transparent; border: 0; padding: .3rem; cursor: pointer; text-decoration: underline; }
.navigation-progress__message button:focus-visible { outline: 2px solid currentColor; outline-offset: 2px; }
@keyframes navigation-loading { from { transform: translateX(-100%); } to { transform: translateX(390%); } }
@media (prefers-reduced-motion: reduce) { .navigation-progress__track span { animation: none; width: 100%; } }
</style>
